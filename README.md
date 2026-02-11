<p align="center">
  <img src="https://github.com/user-attachments/assets/98e1a015-11f1-4365-bffb-002e3879debc" alt="inertiaKIT logo" />
</p>

---

# inertiaKIT

inertiaKIT is a zero-boilerplate approach to file-based routing and typed props in Laravel + InertiaJS created by [Josh Cirre](https://joshcirre.com).
It auto-generates:

- **Routes** (and optional Controllers) from `resources/js/pages/*.server.php`
- **TypeScript interfaces** for your Eloquent models
- **TypeScript interfaces** for your page props, with camelCased keys and model types

---

## Installation

1. **Require the package**
   ```bash
   composer require joshcirre/inertiakit
   ```

2. **Install the package**
   ```bash
   php artisan inertiakit:install
   ```

3. _(Optional but recommended)_ **Install Laravel Wayfinder** for zero-config route-action types:
   ```bash
   composer require laravel/wayfinder
   ```

---

## Configuration

Edit your `config/inertiakit.php` to tailor:

```php
return [
    // Explicit Eloquent models to type, or empty = auto-discover all under app/Models
    'models'         => [],

    // Where to write your generated TS model interfaces
    'types_output'   => 'resources/js/types/models.d.ts',

    // Generate real Controllers under app/Http/Controllers/Generated
    'use_controllers'=> env('INERTIAKIT_USE_CONTROLLERS', true),

    // Where to dump your auto-generated routes file
    'routes_file'    => 'routes/inertiakit.php',

    // Glob patterns under resources/js/pages to ignore
    'ignore'         => [
        'auth/*',
        'settings/*',
        'welcome',
        'dashboard',
    ],
];
```

---

## Usage

Run the generation command manually once, or run the following as needed:

```bash
php artisan inertiakit:generate      # Generate routes, controllers, and types
php artisan inertiakit:model-types   # Generate TypeScript types for models
php artisan inertiakit:page-types    # Generate TypeScript types for page props
```

---

## Defining Page Data & Actions

Each page pairs a React/Vue/Svelte component with a PHP server file (`.server.php`). Use the fluent `ServerPage` API to define your page:

```php
<?php

use InertiaKit\ServerPage;
use InertiaKit\Prop;
use App\Models\Todo;
use Illuminate\Http\Request;

return ServerPage::make('Todos/Index')
    ->middleware('auth')
    ->loader(fn () => [
        'todos' => Todo::all(),
        'completedCount' => Prop::defer(fn () => Todo::where('completed', true)->count()),
        'tags' => Prop::merge(fn () => Todo::pluck('tag')->unique()->values()),
    ])
    ->post('addTodo', function (Request $request) {
        Todo::create($request->validate([
            'title' => 'required|string',
            'completed' => 'boolean',
        ]));
    })
    ->put('updateTodo', function (Todo $todo, Request $request) {
        $todo->update($request->validate([
            'title' => 'string',
            'completed' => 'boolean',
        ]));
    })
    ->delete('deleteTodo', function (Todo $todo) {
        $todo->delete();
    })
    ->types([
        'todos' => 'App\\Models\\Todo[]',
    ]);
```

---

## Inertia 2 Prop Types

InertiaKit integrates with Inertia 2's prop semantics through the `Prop` class. Wrap any prop value in a `Prop` factory to control how Inertia delivers it:

```php
use InertiaKit\Prop;

->loader(fn () => [
    // Standard prop — included on every visit
    'todos' => Todo::all(),

    // Deferred — loaded asynchronously after initial page render
    'stats' => Prop::defer(fn () => Stats::compute()),

    // Deferred with group — batched with other props in the same group
    'permissions' => Prop::defer(fn () => Permission::all())->group('sidebar'),

    // Optional — only included when explicitly requested via partial reload
    'roles' => Prop::optional(fn () => Role::all()),

    // Merge — appended to existing data on subsequent visits (great for pagination)
    'tags' => Prop::merge(fn () => Tag::paginate()),

    // Deep merge — recursively merged into existing nested data
    'config' => Prop::deepMerge(fn () => loadNestedConfig()),

    // Always — included on every request, even partial reloads
    'notifications' => Prop::always(fn () => auth()->user()->unreadNotifications),
])
```

TypeScript types are automatically generated with the correct optionality:
- `defer` and `optional` props become optional properties (`propName?: Type`)
- `merge`, `deepMerge`, and `always` props remain required (`propName: Type`)

---

## Explicit HTTP Method Actions

Define actions with explicit HTTP methods for full control over your route verbs:

```php
return ServerPage::make('Todos/Index')
    ->post('addTodo', function (Request $request) { ... })
    ->put('updateTodo', function (Todo $todo, Request $request) { ... })
    ->patch('toggleTodo', function (Todo $todo, Request $request) { ... })
    ->delete('deleteTodo', function (Todo $todo) { ... })
```

You can also use the generic `->action()` method, which auto-detects the HTTP method based on parameter types:
- **Model only** → `DELETE`
- **Model + Request** → `PUT`
- **Request only** → `POST`

---

## Dynamic Route Parameters

Use `[param]` folder names to create dynamic route segments, SvelteKit-style:

```
resources/js/pages/
├── users/
│   ├── index.server.php          → GET /users
│   └── [user]/
│       └── edit.server.php       → GET /users/{user}/edit
```

The loader receives route-model bound parameters automatically:

```php
<?php
// resources/js/pages/users/[user]/edit.server.php

use App\Models\User;
use InertiaKit\ServerPage;
use InertiaKit\Prop;

return ServerPage::make('Users/Edit')
    ->middleware('auth')
    ->loader(fn (User $user): array => [
        'user' => $user,
        'roles' => Prop::optional(fn () => $user->roles),
    ])
    ->types([
        'user' => User::class,
    ])
    ->put('updateProfile', function (User $user, Request $request) {
        $user->update($request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
        ]));
    });
```

The generated controller automatically includes route-model binding in the method signature, and Laravel resolves the `User` model from the `{user}` URL segment.

> **Note:** Parameterized loaders cannot be executed at build time for type inference. Always add `->types()` to pages with dynamic route parameters.

---

## Client-Side Usage

On the client, InertiaKit injects typed actions and props that you can use:

```tsx
import type { TodosIndexProps } from '@/types/page-props';
import { useForm, usePage } from '@inertiajs/react';
import { addTodo } from '@/actions/App/Http/Controllers/Generated/Pages/TodosIndexController';

export default function TodosIndex() {
  const { props } = usePage<TodosIndexProps>();
  const form = useForm({ title: '', description: '' });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.submit(addTodo(), {
      onSuccess: () => form.setData({ title: '', description: '' }),
      preserveScroll: true,
    });
  };

  return (
    <div>
      <h1>My Todos</h1>
      {props.todos.map((todo) => (
        <div key={todo.id}>
          <p>{todo.title}</p>
        </div>
      ))}

      <form onSubmit={submit}>
        <input
          value={form.data.title}
          onChange={(e) => form.setData('title', e.target.value)}
        />
        <button type="submit" disabled={form.processing}>Add Todo</button>
      </form>
    </div>
  );
}
```

---

## Automatic Re-generation with Vite

For seamless DX, you can hook up a file watcher in your `vite.config.js`. (This is done automatically when you run `inertiakit:install`)

```bash
npm install -D vite-plugin-run
```

And in your `vite.config.js`:

```js
import laravel from 'laravel-vite-plugin';
import { run } from 'vite-plugin-run';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.tsx'],
      ssr:   'resources/js/ssr.tsx',
      refresh: true,
    }),
    run([
      {
        name: 'inertiakit:types',
        run: ['php', 'artisan', 'inertiakit:model-types && php artisan inertiakit:page-types'],
        pattern: ['app/Models/**/*.php', 'resources/js/pages/**/*.server.php'],
      },
    ]),
  ],
});
```

---

## Further Reading

- [InertiaJS Laravel](https://inertiajs.com/server-side-setup)
- [Wayfinder (typed Laravel routes)](https://github.com/tighten/wayfinder)
- [Laravel Vite Plugin](https://github.com/laravel/vite-plugin)
