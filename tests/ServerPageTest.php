<?php

use Illuminate\Http\Request;
use InertiaKit\ServerPage;

it('can create a server page with component name', function () {
    $page = ServerPage::make('Todos/Index');

    expect($page)->toBeInstanceOf(ServerPage::class);
    expect($page->getComponent())->toBe('Todos/Index');
});

it('can add middleware', function () {
    $page = ServerPage::make('Todos/Index')
        ->middleware('auth');

    expect($page->getMiddleware())->toBe(['auth']);
});

it('can add multiple middleware', function () {
    $page = ServerPage::make('Todos/Index')
        ->middleware(['auth', 'verified']);

    expect($page->getMiddleware())->toBe(['auth', 'verified']);
});

it('can set a loader function', function () {
    $loader = fn () => ['todos' => []];

    $page = ServerPage::make('Todos/Index')
        ->loader($loader);

    expect($page->getLoader())->toBe($loader);
});

it('can add actions', function () {
    $action1 = fn (Request $request) => ['success' => true];
    $action2 = fn (Request $request) => ['success' => false];

    $page = ServerPage::make('Todos/Index')
        ->action('create', $action1)
        ->action('update', $action2);

    $actions = $page->getActions();
    expect($actions)->toHaveKey('create');
    expect($actions)->toHaveKey('update');
    expect($actions['create'])->toBe($action1);
    expect($actions['update'])->toBe($action2);
});

it('can set types', function () {
    $page = ServerPage::make('Todos/Index')
        ->types([
            'todos' => 'App\\Models\\Todo[]',
            'user' => 'App\\Models\\User',
        ]);

    expect($page->getTypes())->toBe([
        'todos' => 'App\\Models\\Todo[]',
        'user' => 'App\\Models\\User',
    ]);
});

it('can convert to array format', function () {
    $loader = fn () => ['todos' => []];
    $action = fn (Request $request) => ['success' => true];

    $page = ServerPage::make('Todos/Index')
        ->middleware('auth')
        ->loader($loader)
        ->action('create', $action)
        ->types(['todos' => 'App\\Models\\Todo[]']);

    $array = $page->toArray();

    expect($array)->toHaveKey('load');
    expect($array)->toHaveKey('create');
    expect($array)->toHaveKey('types');
    expect($array)->toHaveKey('middleware');
    expect($array)->toHaveKey('component');

    expect($array['load'])->toBe($loader);
    expect($array['create'])->toBe($action);
    expect($array['types'])->toBe(['todos' => 'App\\Models\\Todo[]']);
    expect($array['middleware'])->toBe(['auth']);
    expect($array['component'])->toBe('Todos/Index');
});

it('toResponse returns the same as toArray', function () {
    $page = ServerPage::make('Todos/Index')
        ->middleware('auth')
        ->loader(fn () => ['todos' => []]);

    expect($page->toResponse())->toBe($page->toArray());
});

it('works with fluent chaining', function () {
    $page = ServerPage::make('Users/Profile')
        ->middleware(['auth', 'verified'])
        ->loader(fn () => [
            'user' => ['name' => 'John Doe'],
            'posts' => [],
        ])
        ->action('updateProfile', fn (Request $request) => ['updated' => true])
        ->action('deleteProfile', fn (Request $request) => ['deleted' => true])
        ->types([
            'user' => 'App\\Models\\User',
            'posts' => 'App\\Models\\Post[]',
        ]);

    expect($page->getComponent())->toBe('Users/Profile');
    expect($page->getMiddleware())->toBe(['auth', 'verified']);
    expect($page->getActions())->toHaveCount(2);
    expect($page->getTypes())->toHaveCount(2);
});
