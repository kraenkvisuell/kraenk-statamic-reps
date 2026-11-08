<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;

/**
 * Writes the structure files the config describes: collections, global sets, navigations and the
 * asset container. Only the keys listed here are managed; anything else in those files (set in the
 * CP or by hand) is kept. Repeatable: a second run reports nothing to do.
 */
class Install extends Command
{
    protected $signature = 'reps:install {--dry-run : Only report what would change}';

    protected $description = 'Write the collections, globals, navigations and asset container of the reps content model';

    // collections whose order editors set (Statamic structure); depth 1 unless listed
    protected const ORDERED = ['pages' => 2, 'artists' => 1, 'categories' => 1, 'disciplines' => 1, 'news' => 1, 'productions' => 1, 'special_categories' => 1, 'projects' => 1, 'skills' => 1, 'clients' => 1];

    protected int $changes = 0;

    public function handle(): int
    {
        foreach (Reps::collections() as $handle) {
            $this->collection($handle);
        }

        $this->write('content/globals/site_settings.yaml', ['title' => 'Site settings'] + $this->globalSites());
        $this->write('content/globals/seo.yaml', ['title' => 'SEO'] + $this->globalSites());

        foreach (['main' => 'Main navigation', 'footer' => 'Footer navigation'] as $handle => $title) {
            $this->write("content/navigation/$handle.yaml", [
                'title' => $title,
                'collections' => ['pages'],
                'max_depth' => 2,
            ]);
        }

        $container = Reps::config('assets.container');
        // starts on the local mirror; switched to Bunny (assets.cloud_disk) after the first upload
        $this->write("content/assets/$container.yaml", [
            'title' => 'Assets',
            'disk' => 'assets',
        ], keepExisting: ['title', 'disk']);

        $this->info($this->changes ? ($this->option('dry-run') ? "$this->changes file(s) would change." : "$this->changes file(s) written.") : 'Nothing to do.');

        return self::SUCCESS;
    }

    protected function collection(string $handle): void
    {
        $route = Reps::config("routes.$handle");
        $data = [
            'title' => Reps::config("labels.$handle", ucwords(str_replace('_', ' ', $handle))),
            'template' => "$handle/show",
            'layout' => 'layout',
            'revisions' => false,
            'route' => $route,
            'sites' => $this->sites(),
            'propagate' => count($this->sites()) > 1,
        ];

        if (isset(static::ORDERED[$handle])) {
            $data['structure'] = ['max_depth' => static::ORDERED[$handle]];
        }

        if ($handle === 'slideshows') {
            $data['dated'] = true;
            $data['sort_dir'] = 'desc';
            $data['date_behavior'] = ['past' => 'public', 'future' => 'public'];
        }

        $this->write("content/collections/$handle.yaml", $data, keepExisting: ['title']);
    }

    /** @return list<string> */
    protected function sites(): array
    {
        return Site::all()->keys()->values()->all();
    }

    protected function globalSites(): array
    {
        if (count($this->sites()) < 2) {
            return [];
        }

        $sites = [];
        foreach ($this->sites() as $i => $site) {
            $sites[$site] = $i === 0 ? null : $this->sites()[0];
        }

        return ['sites' => $sites];
    }

    /**
     * @param  list<string>  $keepExisting  keys only set when the file doesn't have them yet
     */
    protected function write(string $path, array $managed, array $keepExisting = []): void
    {
        $file = base_path($path);
        $current = File::exists($file) ? (YAML::file($file)->parse() ?? []) : [];
        $new = $current;

        foreach ($managed as $key => $value) {
            if (in_array($key, $keepExisting, true) && array_key_exists($key, $current)) {
                continue;
            }

            if ($value === null) {
                unset($new[$key]);

                continue;
            }

            $new[$key] = $value;
        }

        if ($new == $current && File::exists($file)) {
            return;
        }

        $this->changes++;
        $this->line(sprintf('  %s %s', File::exists($file) ? 'update' : 'create', $path));

        foreach (array_unique(array_merge(array_keys($current), array_keys($new))) as $key) {
            if (($current[$key] ?? null) != ($new[$key] ?? null)) {
                $this->line(sprintf('    %s: %s → %s', $key, json_encode($current[$key] ?? null), json_encode($new[$key] ?? null)));
            }
        }

        if (! $this->option('dry-run')) {
            File::ensureDirectoryExists(dirname($file));
            File::put($file, YAML::dump($new));
        }
    }
}
