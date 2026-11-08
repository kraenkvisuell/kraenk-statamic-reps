<?php

namespace Kraenkvisuell\StatamicReps;

use Illuminate\Support\Facades\Event;
use Kraenkvisuell\StatamicReps\Console\Commands;
use Kraenkvisuell\StatamicReps\Listeners\AdjustBlueprints;
use Kraenkvisuell\StatamicReps\Listeners\SyncRelations;
use Kraenkvisuell\StatamicReps\Support\GlobalBlueprints;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $fieldsetNamespace = 'reps';

    protected $commands = [
        Commands\Install::class,
        Commands\Import::class,
        Commands\CopyAssetsToBunny::class,
        Commands\WarmGlideCache::class,
        Commands\RefreshUris::class,
    ];

    protected $tags = [
        Tags\Reps::class,
    ];

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    public function register(): void
    {
        parent::register();

        $defaults = require __DIR__.'/../config/kraenk-statamic-reps.php';
        $site = $this->app['config']->get('kraenk-statamic-reps', []);
        $this->app['config']->set('kraenk-statamic-reps', Reps::mergeConfig($defaults, $site));
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../stubs/kraenk-statamic-reps.php' => config_path('kraenk-statamic-reps.php'),
        ], 'kraenk-statamic-reps-config');

        $this->registerDisks();
        $this->registerLegacyConnection();
        $this->registerBlueprints();

        $this->registerComputedValues();

        Event::listen(EntryBlueprintFound::class, AdjustBlueprints::class);
        Event::listen(EntrySaved::class, SyncRelations::class);
    }

    // disks of the config (Bunny, the local mirror, the old media bucket) unless the site defines them
    protected function registerDisks(): void
    {
        foreach (Reps::config('disks', []) as $name => $disk) {
            if (! $disk || config("filesystems.disks.$name")) {
                continue;
            }

            if (($disk['driver'] ?? null) === 'local' && ! str_starts_with($disk['root'], '/')) {
                $disk['root'] = base_path($disk['root']);
            }

            config(["filesystems.disks.$name" => $disk]);
        }
    }

    protected function registerLegacyConnection(): void
    {
        if (! config('database.connections.legacy')) {
            config(['database.connections.legacy' => Reps::config('legacy.connection')]);
        }
    }

    // slugs of the parent entry, for routes like /slideshows/{artist_slug}/{slug}
    protected function registerComputedValues(): void
    {
        $parentSlug = fn (string $field) => function ($entry) use ($field) {
            $id = collect((array) $entry->get($field))->first();

            return $id ? Blink::once("reps-slug-$id", fn () => Entry::find($id)?->slug()) : null;
        };

        Collection::computed('slideshows', 'artist_slug', $parentSlug('artist'));
        Collection::computed('special_projects', 'special_category_slug', $parentSlug('special_category'));
    }

    protected function registerBlueprints(): void
    {
        $directory = __DIR__.'/../resources/collection-blueprints';

        foreach (Reps::collections() as $handle) {
            // Blueprint::in() looks the namespace up with a dot, makeBlueprintFromFile() with a slash
            Blueprint::addNamespace("collections.$handle", "$directory/$handle");
            Blueprint::addNamespace("collections/$handle", "$directory/$handle");
        }

        foreach (GlobalBlueprints::HANDLES as $handle) {
            Blueprint::setFallback("globals.$handle", fn () => GlobalBlueprints::make($handle));
        }
    }
}
