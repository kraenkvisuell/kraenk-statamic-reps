<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Bastihilger\SimpleStatamicTranslations\Translations as Languages;
use GuzzleHttp\Psr7\Request;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kraenkvisuell\StatamicReps\Reps;
use Kraenkvisuell\StatamicReps\StaticCaching\DeployWarmJob;
use Kraenkvisuell\StatamicReps\StaticCaching\WarmJob;
use Kraenkvisuell\StatamicReps\Translations\SimpleTranslations;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\StaticCaching\Cacher;
use Throwable;

/**
 * Re-caches the site: clears the static cache, then warms the pages visitors see first, in this
 * order: the home page(s), every page linked in the menu (the home page's <nav> elements, so it
 * follows the menu whatever builds it), the artists, the categories. Every language (simple translations' /de,
 * /en, or every site). Everything else is cached on its first visit.
 *
 * Deploys run it, and a save that changes every page (menus, globals …) should too, instead of
 * re-caching thousands of pages. Queued (--queue), the jobs go to
 * kraenk-statamic-reps.static_caching.warm_queue in this order; waiting jobs of an earlier run are
 * dropped first (see clearQueue()). Without --queue the pages are requested right away.
 */
#[Signature('reps:warm {--queue : Queue the requests (Horizon renders them in this order)} {--insecure : Skip SSL verification} {--dry-run : Only list the pages, clear nothing}')]
#[Description('Clear the static cache and warm the home page, the menu pages, the artists and the categories')]
class Warm extends Command
{
    public function handle(Cacher $cacher): int
    {
        if (! $this->option('dry-run')) {
            $cacher->flush();
            $this->line('Static cache cleared.');
        }

        if ($this->option('queue') && ! $this->option('dry-run')) {
            $this->clearQueue();
        }

        $tiers = collect([
            'home' => $this->homePaths(),
            'menu' => fn () => $this->menuPaths(),
            'artists' => fn () => $this->entryPaths('artists'),
            'categories' => fn () => $this->entryPaths('categories'),
        ]);

        $done = collect();

        foreach ($tiers as $name => $paths) {
            $paths = collect(value($paths))->unique()->diff($done)->values();
            $done = $done->merge($paths);

            $this->line(sprintf('<info>%s</info>: %d URLs', $name, $paths->count()));

            if ($this->output->isVerbose() || $this->option('dry-run')) {
                $paths->each(fn ($path) => $this->line("  $path"));
            }

            if (! $this->option('dry-run')) {
                $paths->each(fn ($path) => $this->warm($path));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Drops warm jobs still waiting from an earlier run (a deploy shortly after another one), so
     * this run's tiers start at the front instead of behind thousands of old slideshow pages.
     * Nothing is lost: this run renders every page again. Only a queue of its own is cleared
     * (kraenk-statamic-reps.static_caching.warm_queue), never the default one with other jobs.
     *
     * The warm jobs are unique per URL, and queue:clear leaves their locks behind: this run's
     * jobs for the same URLs would be dropped. So the locks of the waiting jobs are released
     * first.
     */
    protected function clearQueue(): void
    {
        $queue = $this->queue();
        $connection = config('statamic.static_caching.warm_queue_connection') ?? config('queue.default');

        if (! $queue || $queue === config("queue.connections.$connection.queue", 'default')) {
            $this->warn('The warm queue is the default queue: earlier warm jobs stay queued before this run.');

            return;
        }

        try {
            $payloads = $this->waitingPayloads($connection, $queue);
            $released = $this->releaseUniqueLocks($payloads);
            $this->call('queue:clear', ['connection' => $connection, '--queue' => $queue, '--force' => true]);
            $this->line(sprintf('Dropped %d waiting jobs of an earlier run (%s queue, %d locks released).', count($payloads), $queue, $released));
        } catch (Throwable $e) {
            $this->warn("Couldn't clear the $queue queue: ".$e->getMessage());
        }
    }

    protected function queue(): ?string
    {
        return Reps::config('static_caching.warm_queue') ?? config('statamic.static_caching.warm_queue');
    }

    /** @return list<string> the payloads of the jobs waiting on the queue (Redis or database) */
    protected function waitingPayloads(string $connection, string $queue): array
    {
        $driver = Queue::connection($connection);

        if ($driver instanceof RedisQueue) {
            $key = $driver->getQueue($queue);
            $redis = $driver->getConnection();

            return [...$redis->lrange($key, 0, -1), ...$redis->zrange("$key:delayed", 0, -1)];
        }

        if ($driver instanceof DatabaseQueue) {
            return DB::connection(config("queue.connections.$connection.connection"))
                ->table(config("queue.connections.$connection.table", 'jobs'))
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->pluck('payload')
                ->all();
        }

        return [];
    }

    protected function releaseUniqueLocks(array $payloads): int
    {
        $locks = new UniqueLock(app(Cache::class));
        $released = 0;

        foreach ($payloads as $payload) {
            $command = json_decode($payload, true)['data']['command'] ?? null;
            $job = is_string($command) ? rescue(fn () => unserialize($command), report: false) : null;

            if ($job instanceof ShouldBeUnique) {
                $locks->release($job);
                $released++;
            }
        }

        return $released;
    }

    protected function warm(string $path): void
    {
        $request = new Request('GET', URL::makeAbsolute($path));
        $config = $this->clientConfig();

        if (! $this->option('queue')) {
            try {
                (new WarmJob($request, $config))->handle();
            } catch (Throwable $e) {
                $this->warn("$path: ".$e->getMessage());
            }

            return;
        }

        DeployWarmJob::dispatch($request, $config)
            ->onConnection(config('statamic.static_caching.warm_queue_connection') ?? config('queue.default'))
            ->onQueue($this->queue());
    }

    /** Guzzle options of the warm requests (the CDN bypass is added by WarmJob itself). */
    protected function clientConfig(): array
    {
        return ['verify' => ! $this->insecure(), 'timeout' => 300];
    }

    /** @return list<string> the root of every language (or site) */
    protected function homePaths(): array
    {
        if (SimpleTranslations::enabled()) {
            return collect(Languages::codes())->map(fn ($code) => '/'.$code)->all();
        }

        return Site::all()->map(fn ($site) => $this->path($site->absoluteUrl()))->values()->all();
    }

    /** Internal links in the <nav> elements of every home page. */
    protected function menuPaths(): Collection
    {
        return collect($this->homePaths())->flatMap(function ($home) {
            try {
                $url = URL::makeAbsolute($home);
                $options = $this->clientConfig();

                if ($ip = Reps::config('static_caching.warm_ip')) {
                    $options['curl'][CURLOPT_RESOLVE] = [WarmJob::resolve($url, $ip)];
                }

                $response = Http::withOptions($options)->get($url);
            } catch (Throwable $e) {
                $this->warn("$home: ".$e->getMessage());

                return [];
            }

            if (! $response->successful()) {
                $this->warn("$home: HTTP {$response->status()}");

                return [];
            }

            return $this->navLinks($response->body());
        })->map(fn ($href) => $this->path($href))->filter()->values();
    }

    /** @return list<string> hrefs of the links inside <nav> elements */
    protected function navLinks(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $links = [];

        foreach ((new \DOMXPath($dom))->query('//nav//a[@href]') as $link) {
            $links[] = $link->getAttribute('href');
        }

        return $links;
    }

    /** The published entries of a collection with a page (artists, categories), in every language (or site). */
    protected function entryPaths(string $collection): Collection
    {
        $entries = Entry::query()->where('collection', $collection)->where('published', true)->get();

        return $entries->flatMap(function ($entry) {
            $urls = SimpleTranslations::enabled()
                ? collect(Languages::codes())->map(fn ($code) => Languages::in($code, fn () => $entry->url()))
                : collect([$entry->url()]);

            return $urls->map(fn ($url) => $this->path($url));
        })->filter()->values();
    }

    /** The path of an internal URL ('/de/news'), null for other hosts, anchors, mailto: … */
    protected function path(?string $url): ?string
    {
        if (! $url || str_starts_with($url, '#') || preg_match('/^[a-z]+:(?!\/\/)/i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        if (isset($parts['host']) && $parts['host'] !== $host) {
            return null;
        }

        return '/'.trim($parts['path'] ?? '', '/');
    }

    protected function insecure(): bool
    {
        return $this->option('insecure') || config('statamic.static_caching.warm_insecure', false);
    }
}
