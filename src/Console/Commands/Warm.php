<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Bastihilger\SimpleStatamicTranslations\Translations as Languages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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
 * this order, since jobs run in the order they were queued.
 */
#[Signature('reps:warm {--queue : Queue the requests (Horizon renders them in tier order)} {--insecure : Skip SSL verification} {--uncached : Only warm uncached URLs} {--dry-run : Only list the tiers}')]
#[Description('Warm the static cache in tiers: home, menu, artists, the rest')]
class Warm extends Command
{
    public function handle(): int
    {
        $tiers = collect([
            'home' => $this->homePaths(),
            'menu' => fn () => $this->menuPaths(),
            'artists' => fn () => $this->artistPaths(),
        ]);

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

        $result = Process::forever()->run($command, fn ($type, $output) => $this->output->write($output));

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
