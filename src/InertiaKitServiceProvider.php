<?php

public function boot()
{
    // 1) publish config
    $this->publishes([
      __DIR__.'/config/inertiakit.php' => config_path('inertiakit.php'),
    ], 'config');

    // 2) merge config so defaults apply
    $this->mergeConfigFrom(
      __DIR__.'/config/inertiakit.php',
      'inertiakit'
    );

    // 3) register your three commands
    if ($this->app->runningInConsole()) {
        $this->commands([
            Console\GenerateInertiaKitRoutes::class,
            Console\GenerateModelTypes::class,
            Console\GeneratePagePropTypes::class,
        ]);
    }
}
