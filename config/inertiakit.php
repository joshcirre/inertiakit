<?php

/*
|--------------------------------------------------------------------------
| InertiaKit Configuration
|--------------------------------------------------------------------------
|
| Here you may configure the settings for the InertiaKit package,
| including which models to type, where to emit generated files,
| whether to use controller stubs, and which folders to ignore.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Models to Generate Types For
    |--------------------------------------------------------------------------
    |
    | List explicit Eloquent model classes here if you only want a subset
    | typed. Leave empty to auto-discover every PHP file under app/Models.
    |
    | Example:
    | 'models' => [
    |     App\Models\User::class,
    |     App\Models\Post::class,
    | ],
    */
    'models' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated Models Output Path
    |--------------------------------------------------------------------------
    |
    | Relative to your project root. The `inertiakit:model-types` command
    | will write out your TS interfaces here.
    |
    */
    'types_output' => 'resources/js/types/models.d.ts',

    /*
    |--------------------------------------------------------------------------
    | Use Controller Stubs
    |--------------------------------------------------------------------------
    |
    | If true, `inertiakit:generate-routes` will scaffold real Laravel
    | controllers under app/Http/Controllers/Generated/Pages. If false,
    | it will register closures in your routes file instead.
    |
    */
    'use_controllers' => env('INERTIAKIT_USE_CONTROLLERS', true),

    /*
    |--------------------------------------------------------------------------
    | Routes File
    |--------------------------------------------------------------------------
    |
    | Relative path to where the `inertiakit:generate-routes` command will
    | dump your auto-generated Inertia routes.
    |
    */
    'routes_file' => 'routes/inertiakit.php',

    /*
    |--------------------------------------------------------------------------
    | Page Folders to Ignore
    |--------------------------------------------------------------------------
    |
    | Any top-level folder (or glob pattern) under resources/js/pages
    | listed here will be skipped entirely (no routes, no controllers).
    | Supports '*' wildcards.
    |
    | Examples:
    |  - 'auth/*'     (skip all pages under resources/js/pages/auth)
    |  - 'welcome'    (skip resources/js/pages/welcome.server.php and welcome.tsx)
    |
    */
    'ignore' => [
        'auth/*',
        'settings/*',
        'welcome',
        'dashboard',
    ],

];
