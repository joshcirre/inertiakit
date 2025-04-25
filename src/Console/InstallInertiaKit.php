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
        // 1) Determine which file we should target
        $tsFile = base_path('vite.config.ts');
        $jsFile = base_path('vite.config.js');
        if (File::exists($tsFile)) {
            $viteFile = $tsFile;
        } elseif (File::exists($jsFile)) {
            $viteFile = $jsFile;
        } else {
            // neither exists: publish stub to vite.config.js
            $stub     = base_path('vendor/joshcirre/inertia-kit/stubs/vite.config.js.stub');
            if (File::exists($stub)) {
                File::copy($stub, $jsFile);
                $this->info("✅ Published vite.config.js stub with InertiaKit plugin.");
            } else {
                $this->warn("Vite stub not found at {$stub}. Install manually.");
            }
            return;
        }

        // 2) Read existing config
        $contents = File::get($viteFile);

        // 3) Ensure `run` import
        if (! str_contains($contents, "from 'vite-plugin-run'")) {
            // Insert after last import line
            $contents = preg_replace(
                '/(import .+?;\\s*)(?!import)/s',
                "$1\nimport { run } from 'vite-plugin-run';\n\n",
                $contents,
                1
            );
            $this->info("✅ Added `import { run } from 'vite-plugin-run';` to {$viteFile}");
        }

        // 4) Prepare our plugin snippet
        $snippet = <<<'JS'
            run([
                {
                    name: 'inertiakit',
                    run: ['php', 'artisan', 'inertiakit:generate'],
                    pattern: ['resources/js/**/*.tsx', 'resources/js/**/*.server.php', 'app/**/Models/**/*.php'],
                },
            ]),
    JS;

        // 5) Inject into plugins array
        if (preg_match('/plugins\s*:\s*\[\s*([\s\S]*?)\]/m', $contents)) {
            if (! str_contains($contents, 'inertiakit:generate')) {
                $contents = preg_replace_callback(
                    '/plugins\s*:\s*\[\s*([\s\S]*?)\s*\]/m',
                    function ($m) use ($snippet) {
                        $inner = rtrim($m[1]);
                        return "plugins: [\n{$inner}\n{$snippet}\n]";
                    },
                    $contents,
                    1
                );
                File::put($viteFile, $contents);
                $this->info("✅ Injected inertiaKIT run(...) into `plugins` of {$viteFile}");
            } else {
                $this->info("⬢ inertiaKIT plugin already in `plugins` of {$viteFile}`");
            }
        } else {
            $this->warn("Could not locate `plugins: [ … ]` in {$viteFile}; please add:\n\n{$snippet}");
        }
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
