<?php

namespace Kraenkvisuell\StatamicReps;

use Illuminate\Support\Facades\Event;
use Kraenkvisuell\StatamicReps\Console\Commands;
use Kraenkvisuell\StatamicReps\Listeners\AdjustBlueprints;
use Kraenkvisuell\StatamicReps\Listeners\SyncRelations;
use Kraenkvisuell\StatamicReps\Support\GlobalBlueprints;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Blueprint;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $fieldsetNamespace = 'reps';

    protected $commands = [
        Commands\Install::class,
        Commands\Import::class,
        Commands\CopyAssetsToBunny::class,
        Commands\WarmGlideCache::class,
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

        Event::listen(EntryBlueprintFound::class, AdjustBlueprints::class);
        Event::listen(EntrySaved::class, SyncRelations::class);
    }

    // Bunny Storage (S3 API) for the asset container and the Glide cache, unless the site defines them
    protected function registerDisks(): void
    {
        foreach (['bunny-assets' => 'assets', 'bunny-glide-cache' => 'glide-cache'] as $disk => $root) {
            if (config("filesystems.disks.$disk")) {
                continue;
            }

            config(["filesystems.disks.$disk" => [
                'driver' => 's3',
                'key' => env('BUNNY_S3_KEY'),
                'secret' => env('BUNNY_S3_SECRET'),
                'region' => env('BUNNY_S3_REGION', 'de'),
                'bucket' => env('BUNNY_S3_BUCKET'),
                'endpoint' => env('BUNNY_S3_ENDPOINT', 'https://de-s3.storage.bunnycdn.com'),
                'url' => env('BUNNY_PUBLIC_URL'),
                'root' => $root,
                'use_path_style_endpoint' => true,
                'throw' => false,
            ]]);
        }

        if (! config('filesystems.disks.assets')) {
            config(['filesystems.disks.assets' => [
                'driver' => 'local',
                'root' => public_path('assets'),
                'url' => '/assets',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ]]);
        }
    }

    protected function registerLegacyConnection(): void
    {
        if (! config('database.connections.legacy')) {
            config(['database.connections.legacy' => Reps::config('legacy.connection')]);
        }
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
