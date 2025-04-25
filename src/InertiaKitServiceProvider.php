<?php

namespace JoshCirre\InertiaKit;

use Illuminate\Support\ServiceProvider;
use JoshCirre\InertiaKit\Console\GenerateInertiaKitRoutes;
use JoshCirre\InertiaKit\Console\GenerateModelTypes;
use JoshCirre\InertiaKit\Console\GeneratePagePropTypes;

class InertiaKitServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Publish config file
        $this->publishes([
            __DIR__.'/config/inertiakit.php' => config_path('inertiakit.php'),
        ], 'config');

        // Only register commands when running in the console
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateInertiaKitRoutes::class,
                GenerateModelTypes::class,
                GeneratePagePropTypes::class,
            ]);
        }

        // Merge default config
        $this->mergeConfigFrom(
            __DIR__.'/config/inertiakit.php',
            'inertiakit'
        );
    }
}
