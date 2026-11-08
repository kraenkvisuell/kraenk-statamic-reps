<?php

namespace Kraenkvisuell\StatamicReps;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/kraenk-statamic-reps.php', 'kraenk-statamic-reps');
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/kraenk-statamic-reps.php' => config_path('kraenk-statamic-reps.php'),
        ], 'kraenk-statamic-reps-config');
    }
}
