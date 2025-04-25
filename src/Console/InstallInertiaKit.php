<?php

namespace JoshCirre\InertiaKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class InstallInertiaKit extends Command
{
    protected $signature = 'inertiakit:install';

    protected $description = 'Publish config, wire Vite stub, register InertiaKit routes, and run initial generators';

    public function handle()
    {
        // Publish the package config
        $this->info('📦 Publishing InertiaKit config...');
        $this->call('vendor:publish', [
            '--provider' => 'JoshCirre\\InertiaKit\\InertiaKitServiceProvider',
            '--tag' => 'config',
        ]);

        // Wire up the Vite plugin stub
        $this->injectVitePlugin();

        // Run the generators
        $this->info('⚙️  Running initial InertiaKit generators…');
        $this->call('inertiakit:generate');

        // finally, install the npm watcher tools
        $this->installNpmDependencies();

        $this->info('🎉 inertiaKIT installation complete!');
    }

    protected function injectVitePlugin(): void
    {
        $viteFile = base_path('vite.config.js');

        if (! File::exists($viteFile)) {
            $this->warn('vite.config.js not found; skipping Vite integration.');

            return;
        }

        $contents = File::get($viteFile);

        // 1) Inject the `run` import if it doesn't already exist
        if (! str_contains($contents, "from 'vite-plugin-run'")) {
            // Find the last `import ...` line
            $contents = preg_replace(
                '/(import .+?;\s*)(?!import)/s',
                "$1\nimport { run } from 'vite-plugin-run';\n\n",
                $contents,
                1
            );
            $this->info("✅ Added `import { run } from 'vite-plugin-run';`");
        } else {
            $this->info('⬢ `run` import already present');
        }

        // 2) Prepare our run-plugin snippet
        $snippet = <<<'JS'
            run([
                {
                    name: 'inertiakit',
                    run: ['php', 'artisan', 'inertiakit:generate'],
                    pattern: [
                    'resources/js/**/*.tsx',
                    'resources/js/**/*.vue',
                    'resources/js/**/*.svelte',
                    'resources/js/**/*.server.php',
                    'app/**/Models/**/*.php',
                    ],
                },
            ]),
    JS;

        // 3) Inject into the plugins array
        // This regex finds `plugins: [ ... ]` and inserts our snippet before the closing ]
        if (preg_match('/plugins\s*:\s*\[\s*([\s\S]*?)\]/m', $contents)) {
            if (! str_contains($contents, 'inertiakit:generate')) {
                $contents = preg_replace_callback(
                    '/plugins\s*:\s*\[\s*([\s\S]*?)\s*\]/m',
                    function ($m) use ($snippet) {
                        // $m[1] is what's already inside the plugins [ ... ]
                        $inner = rtrim($m[1]);

                        return "plugins: [\n{$inner}\n{$snippet}\n]";
                    },
                    $contents,
                    1
                );
                File::put($viteFile, $contents);
                $this->info('✅ Injected inertiaKIT run(...) into `plugins` array');
            } else {
                $this->info('⬢ inertiaKIT plugin already in `plugins`');
            }
        } else {
            $this->warn("Could not find `plugins: [ ... ]` in vite.config.js; please add:\n\n{$snippet}");
        }

        $this->line('👉 Don’t forget to install npm watcher deps if you haven’t already:');
        $this->line('   npm install --save-dev chokidar-cli concurrently vite-plugin-run');
    }

    protected function installNpmDependencies(): void
    {
        $this->info('📦 Installing npm dev dependencies…');

        // the deps we need for the watcher
        $deps = [
            'vite-plugin-run',
        ];

        $process = new Process(['npm', 'install', '-D', ...$deps]);
        // run in your project root
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->info('✅ npm dependencies installed');
    }
}
