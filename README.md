<p align="center">
  <img src="https://github.com/user-attachments/assets/98e1a015-11f1-4365-bffb-002e3879debc" alt="inertiaKIT logo" />
</p>

---

# inertiaKIT (WIP)

inertiaKIT is a zero-boilerplate approach to file-based routing and typed props in Laravel + InertiaJS created by [Josh Cirre](https://joshcirre.com).
It auto-generates:

- **Routes** (and optional Controllers) from `resources/js/pages/*.server.php`
- **TypeScript interfaces** for your Eloquent models
- **TypeScript interfaces** for your page props, with camelCased keys and model types

> ⚠️ _Alpha software—expect rough edges!_

---

## 📦 Installation

1. **Require the package**
   ```bash
   composer require joshcirre/inertia-kit:^0.0.10-alpha
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

## ⚙️ Configuration

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

## 🚀 Usage

Run the generation command manually once `php artisan inertiakit:generate`, or run the following as needed:

```bash
php artisan inertiakit:generate
php artisan inertiakit:model-types
php artisan inertiakit:page-types
```

## 🖌️ Defining Page Data & Actions

Each page pairs a React component (.tsx) with a PHP server file (.server.php). Use the fluent ServerPage API to define your page:

```php
<?php

use InertiaKit\ServerPage;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

return ServerPage::make('Todos/Index')
    ->middleware('auth')
    ->loader(fn() => [
        'todos' => Todo::all(),
    ])
    ->action('addTodo', function (Request $request) {
        $data = $request->validate([
            'title' => 'required|string', 
            'description' => 'required|string'
        ]);
        Auth::user()->todos()->create($data);

        return back();
    })
    ->action('deleteTodo', function (Todo $todo) {
        $todo->delete();

        return back();
    })
    ->action('sayHi', function () {
        return 'Hello, World!';
    })
    ->types([
        'todos' => 'App\\Models\\Todo[]',
    ]);
```

On the client, InertiaKit injects typed actions and props that you can use:

```ts
import type { TodosIndexProps } from '@/types/page-props';
import { Link, useForm, usePage } from '@inertiajs/react';

import { addTodo } from '@/actions/App/Http/Controllers/Generated/Pages/TodosIndexController';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import todos from '@/routes/todos';
import deleteTodo from '@/routes/todos/index/deleteTodo';

type CreateTodoForm = {
  title: string;
  description: string;
};

export default function TodosIndex() {
  const { props } = usePage<TodosIndexProps>();
  const form = useForm<CreateTodoForm>();

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.submit(addTodo(), {
      onSuccess: () => form.setData({ title: '', description: '' }),
      preserveScroll: true,
    });
  };

  return (
    <AppLayout>
      <h1>My Todos</h1>

      {props.todos.map((todo) => (
        <div key={todo.id}>
          <p>{todo.title}</p>
          <p>{todo.description}</p>
          <Link href={deleteTodo(todo.id)}>
            <button type="button">Delete</button>
          </Link>
        </div>
      ))}

      <form onSubmit={submit}>
        <label htmlFor="title">Title</label>
        <input
          id="title"
          value={form.data.title}
          onChange={(e) => form.setData('title', e.target.value)}
        />
        <InputError message={form.errors.title} />

        <label htmlFor="description">Description</label>
        <input
          id="description"
          value={form.data.description}
          onChange={(e) => form.setData('description', e.target.value)}
        />
        <InputError message={form.errors.description} />

        <button type="submit" disabled={form.processing}>
          Add Todo
        </button>
      </form>

      <Link href={todos.about()}>About todos…</Link>
    </AppLayout>
  );
}
```

---

## 🔄 Automatic Re-generation with Vite

For seamless DX, you can hook up a file watcher in your `vite.config.js`. (this is done automatically when you run `inertiakit:install`)

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

## 📖 Further Reading

- [InertiaJS Laravel](https://inertiajs.com/server-side-setup)
- [Wayfinder (typed Laravel routes)](https://github.com/tighten/wayfinder)
- [Laravel Vite Plugin](https://github.com/laravel/vite-plugin)

Happy coding! 🎉
