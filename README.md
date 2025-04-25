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
