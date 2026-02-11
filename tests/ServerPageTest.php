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

it('can add actions with generic action method', function () {
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

it('stores null method for generic action', function () {
    $page = ServerPage::make('Todos/Index')
        ->action('create', fn () => []);

    expect($page->getActionMethod('create'))->toBeNull();
});

it('can add post action', function () {
    $cb = fn (Request $request) => ['created' => true];

    $page = ServerPage::make('Todos/Index')
        ->post('addTodo', $cb);

    expect($page->getActionCallback('addTodo'))->toBe($cb);
    expect($page->getActionMethod('addTodo'))->toBe('post');
});

it('can add put action', function () {
    $cb = fn () => ['updated' => true];

    $page = ServerPage::make('Todos/Index')
        ->put('updateTodo', $cb);

    expect($page->getActionCallback('updateTodo'))->toBe($cb);
    expect($page->getActionMethod('updateTodo'))->toBe('put');
});

it('can add patch action', function () {
    $cb = fn () => ['patched' => true];

    $page = ServerPage::make('Todos/Index')
        ->patch('toggleTodo', $cb);

    expect($page->getActionCallback('toggleTodo'))->toBe($cb);
    expect($page->getActionMethod('toggleTodo'))->toBe('patch');
});

it('can add delete action', function () {
    $cb = fn () => ['deleted' => true];

    $page = ServerPage::make('Todos/Index')
        ->delete('removeTodo', $cb);

    expect($page->getActionCallback('removeTodo'))->toBe($cb);
    expect($page->getActionMethod('removeTodo'))->toBe('delete');
});

it('getActionCallback returns null for non-existent action', function () {
    $page = ServerPage::make('Todos/Index');

    expect($page->getActionCallback('nonExistent'))->toBeNull();
});

it('getActionMethod returns null for non-existent action', function () {
    $page = ServerPage::make('Todos/Index');

    expect($page->getActionMethod('nonExistent'))->toBeNull();
});

it('getActions returns callbacks only (backward compat)', function () {
    $cb1 = fn () => [];
    $cb2 = fn () => [];

    $page = ServerPage::make('Todos/Index')
        ->post('create', $cb1)
        ->delete('remove', $cb2);

    $actions = $page->getActions();
    expect($actions['create'])->toBe($cb1);
    expect($actions['remove'])->toBe($cb2);
});

it('getRawActions returns full action data with method', function () {
    $cb = fn () => [];

    $page = ServerPage::make('Todos/Index')
        ->put('update', $cb);

    $raw = $page->getRawActions();
    expect($raw['update'])->toBe(['callback' => $cb, 'method' => 'put']);
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

it('can convert to array format with backward compat', function () {
    $loader = fn () => ['todos' => []];
    $action = fn (Request $request) => ['success' => true];

    $page = ServerPage::make('Todos/Index')
        ->middleware('auth')
        ->loader($loader)
        ->post('create', $action)
        ->types(['todos' => 'App\\Models\\Todo[]']);

    $array = $page->toArray();

    expect($array)->toHaveKey('load');
    expect($array)->toHaveKey('create');
    expect($array)->toHaveKey('types');
    expect($array)->toHaveKey('middleware');
    expect($array)->toHaveKey('component');

    expect($array['load'])->toBe($loader);
    // toArray flattens to plain callback
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

it('works with fluent chaining including HTTP method actions', function () {
    $page = ServerPage::make('Users/Profile')
        ->middleware(['auth', 'verified'])
        ->loader(fn () => [
            'user' => ['name' => 'John Doe'],
            'posts' => [],
        ])
        ->post('createPost', fn (Request $request) => ['created' => true])
        ->put('updateProfile', fn (Request $request) => ['updated' => true])
        ->delete('deleteProfile', fn () => ['deleted' => true])
        ->types([
            'user' => 'App\\Models\\User',
            'posts' => 'App\\Models\\Post[]',
        ]);

    expect($page->getComponent())->toBe('Users/Profile');
    expect($page->getMiddleware())->toBe(['auth', 'verified']);
    expect($page->getActions())->toHaveCount(3);
    expect($page->getActionMethod('createPost'))->toBe('post');
    expect($page->getActionMethod('updateProfile'))->toBe('put');
    expect($page->getActionMethod('deleteProfile'))->toBe('delete');
    expect($page->getTypes())->toHaveCount(2);
});
