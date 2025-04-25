import { run } from 'vite-plugin-run';

export default {
  plugins: [
    run([
        {
            name: 'inertiakit',
            run: ['php', 'artisan', 'inertiakit:generate'],
            pattern: ['resources/js/**/*.tsx', 'resources/js/**/*.server.php', 'app/**/Models/**/*.php'],
        },
    ]),
  ],
};
