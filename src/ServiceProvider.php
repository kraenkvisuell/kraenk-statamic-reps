<?php

namespace Kraenkvisuell\StatamicReps;

use Illuminate\Support\Facades\Event;
use Kraenkvisuell\StatamicReps\Console\Commands;
use Kraenkvisuell\StatamicReps\Imaging\OriginalGifManipulator;
use Kraenkvisuell\StatamicReps\Listeners\AdjustBlueprints;
use Kraenkvisuell\StatamicReps\Listeners\SyncRelations;
use Kraenkvisuell\StatamicReps\Support\GlobalBlueprints;
use Statamic\Contracts\Imaging\ImageManipulator;
use Statamic\Contracts\Imaging\UrlBuilder;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesBlueprintFound;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $fieldsetNamespace = 'reps';

    // listing cells with HTML, copy buttons (plain JS, see the file)
    protected $scripts = [__DIR__.'/../resources/js/cp.js'];

    protected $stylesheets = [__DIR__.'/../resources/css/cp.css'];

    protected $commands = [
        Commands\Install::class,
        Commands\Import::class,
        Commands\CopyAssetsToBunny::class,
        Commands\WarmGlideCache::class,
        Commands\WarmAssetMeta::class,
        Commands\RefreshUris::class,
        Commands\Resync::class,
    ];

    protected $tags = [
        Tags\Reps::class,
    ];

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
        'cp' => __DIR__.'/../routes/cp.php',
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

        // German CP labels for the addon's blueprints (the CP translates field labels with __());
        // a site's own lang/de.json overrides them
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        $this->registerDisks();
        $this->registerImaging();
        $this->registerLegacyConnection();
        $this->registerBlueprints();

        $this->registerComputedValues();

        Event::listen(EntryBlueprintFound::class, AdjustBlueprints::class);
        Event::listen(EntrySaved::class, SyncRelations::class);
        Event::listen(EntrySaving::class, [Translations\Listener::class, 'entrySaving']);
        Event::listen(GlobalVariablesSaving::class, [Translations\Listener::class, 'globalsSaving']);
        Event::listen(GlobalVariablesSaved::class, [Translations\Listener::class, 'globalsSaved']);
        // global blueprints have no parent; translatable fields need the variables to find the other sites
        Event::listen(GlobalVariablesBlueprintFound::class, fn ($event) => $event->globals && $event->blueprint->setParent($event->globals));
        $this->app['router']->pushMiddlewareToGroup('statamic.cp.authenticated', Http\Middleware\EditInDefaultSite::class);
        Statamic::provideToScript([
            'repsTranslatableFields' => Translations\Translations::enabled(),
            // labels of the work flags for the actions of a work row (resources/js/cp.js)
            'repsWorkFlags' => [
                'show_in_overview' => 'Show in overview',
                'is_portfolio_image' => Reps::label('portfolio_image'),
                'is_startpage_image' => Reps::label('startpage_image'),
                'is_discipline_image' => 'Discipline image',
            ],
        ]);

        $this->app['router']->pushMiddlewareToGroup('statamic.web', Http\Middleware\RedirectOtherSiteSlugs::class);
    }

    // GIFs keep their original file instead of a Glide copy (see OriginalGifManipulator)
    protected function registerImaging(): void
    {
        if (Reps::config('imaging.original_gifs', true)) {
            $this->app->bind(ImageManipulator::class, fn ($app) => new OriginalGifManipulator($app->make(UrlBuilder::class)));
        }
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
        // the reference is read from the origin (localizations inherit it), the slug is the parent's
        // slug in the entry's own site
        $parentSlug = fn (string $field) => function ($entry) use ($field) {
            $id = collect((array) $entry->root()->get($field))->first();
            $site = $entry->locale();

            return $id ? Blink::once("reps-slug-$id-$site", function () use ($id, $site) {
                $parent = Entry::find($id);

                return ($parent?->in($site) ?? $parent)?->slug();
            }) : null;
        };

        Collection::computed('slideshows', 'artist_slug', $parentSlug('artist'));
        Collection::computed('special_projects', 'special_category_slug', $parentSlug('special_category'));
        Collection::computed('artists', 'discipline_slug', $parentSlug('disciplines'));
        Cp\ArtistListing::register();

        Collection::computed('categories', 'route_segment', fn ($entry) => Reps::config('category_route_segments.'.$entry->root()->slug())
            ?? Reps::config('category_route_segment', 'category'));
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
