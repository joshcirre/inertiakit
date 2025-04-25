import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { run } from 'vite-plugin-run';
import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  plugins: [
    laravel({
      input: [''], // Add entry files based on front end framework of choice
      ssr:   '',
      refresh: true,
    }),
    tailwindcss(),
    run([
      {
        name: 'inertiakit',
        run: ['php','artisan','inertiakit:generate'],
        pattern: [
          'resources/js/**/*.tsx',
          'resources/js/**/*.vue',
          'resources/js/**/*.svelte',
          'resources/js/**/*.server.php',
          'app/**/Models/**/*.php',
        ],
      },
    ]),
  ],
});
