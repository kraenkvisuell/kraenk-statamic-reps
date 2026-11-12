<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Bastihilger\SimpleStatamicTranslations\Translations as Languages;
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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Kraenkvisuell\StatamicReps\Reps;
use Kraenkvisuell\StatamicReps\Translations\SimpleTranslations;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Throwable;

/**
 * Statamic's static:warm in tiers, so the pages visitors see first are cached first: the home
 * page(s), every page linked in the menu (the home page's <nav> elements, so it follows the
 * menu whatever builds it), the artists, then everything else. Every language (simple
 * translations' /de, /en, or every site). Each tier is one static:warm run limited with
 * --include; the last one excludes all of them. Queued (--queue), Horizon renders the tiers in
 * this order, since jobs run in the order they were queued; waiting jobs of an earlier run are
 * dropped first (see clearQueue()). Queued runs go to kraenk-statamic-reps.static_caching.warm_queue,
 * so Statamic's warm_queue can keep the re-caching after saves ahead of them.
 */
#[Signature('reps:warm {--queue : Queue the requests (Horizon renders them in tier order)} {--insecure : Skip SSL verification} {--uncached : Only warm uncached URLs} {--dry-run : Only list the tiers}')]
#[Description('Warm the static cache in tiers: home, menu, artists, the rest')]
class Warm extends Command
{
    /** Tells the static:warm processes which queue to use (read by the ServiceProvider). */
    public const QUEUE_ENV = 'REPS_WARM_QUEUE';

    public function handle(): int
    {
        $tiers = collect([
            'home' => $this->homePaths(),
            'menu' => fn () => $this->menuPaths(),
            'artists' => fn () => $this->artistPaths(),
        ]);

        if ($this->option('queue') && ! $this->option('dry-run')) {
            $this->clearQueue();
        }

        $done = collect();

        foreach ($tiers as $name => $paths) {
            $paths = collect(value($paths))->unique()->diff($done)->values();
            $done = $done->merge($paths);

            $this->line(sprintf('<info>%s</info>: %d URLs', $name, $paths->count()));

            if ($this->output->isVerbose() || $this->option('dry-run')) {
                $paths->each(fn ($path) => $this->line("  $path"));
            }

            if ($paths->isNotEmpty() && ! $this->option('dry-run')) {
                $this->warm(['--include' => $paths->implode(',')]);
            }
        }

        $this->line('<info>rest</info>: everything else static:warm finds');

        if (! $this->option('dry-run')) {
            $this->warm($done->isEmpty() ? [] : ['--exclude' => $done->implode(',')]);
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

    /**
     * One static:warm run per tier, each in its own process: the command keeps its URL list on
     * the instance, and $this->call() would reuse the instance of the first tier.
     */
    protected function warm(array $options): void
    {
        $options = array_filter([
            ...$options,
            '--queue' => $this->option('queue'),
            '--insecure' => $this->option('insecure'),
            '--uncached' => $this->option('uncached'),
        ]);

        $command = [PHP_BINARY, base_path('artisan'), 'statamic:static:warm'];

        foreach ($options as $option => $value) {
            $command[] = $value === true ? $option : "$option=$value";
        }

        $result = Process::forever()->env([self::QUEUE_ENV => (string) $this->queue()])->run($command, fn ($type, $output) => $this->output->write($output));

        if ($result->failed()) {
            throw new \RuntimeException('static:warm failed: '.$result->errorOutput());
        }
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
                $response = Http::withOptions(['verify' => ! $this->insecure()])->timeout(300)->get(URL::makeAbsolute($home));
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

    /** Published artists in every language (or site). */
    protected function artistPaths(): Collection
    {
        $artists = Entry::query()->where('collection', 'artists')->where('published', true)->get();

        return $artists->flatMap(function ($artist) {
            $urls = SimpleTranslations::enabled()
                ? collect(Languages::codes())->map(fn ($code) => Languages::in($code, fn () => $artist->url()))
                : collect([$artist->url()]);

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
