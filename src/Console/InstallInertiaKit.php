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
        // Choose ts or js
        $tsFile = base_path('vite.config.ts');
        $jsFile = base_path('vite.config.js');
        if (File::exists($tsFile)) {
            $viteFile = $tsFile;
        } elseif (File::exists($jsFile)) {
            $viteFile = $jsFile;
        } else {
            // No config: publish the full stub as js
            $stub = base_path('vendor/joshcirre/inertia-kit/stubs/vite.config.js.stub');
            if (File::exists($stub)) {
                File::copy($stub, $jsFile);
                $this->info('✅ Published vite.config.js stub');
            } else {
                $this->warn("No vite.config.* found, and stub is missing at {$stub}");
            }

            return;
        }

        $lines = explode("\n", File::get($viteFile));
        $out = [];
        $inImports = true;
        $haveRunImport = false;
        $inPlugins = false;

        // Prepare the plugin snippet lines
        $snippet = [
            '        run([',
            '            {',
            "                name: 'inertiakit',",
            "                run: ['php', 'artisan', 'inertiakit:generate'],",
            "                pattern: ['resources/js/**/*.tsx', 'resources/js/**/*.server.php', 'app/**/Models/**/*.php'],",
            '            },',
            '        ]),',
        ];

        foreach ($lines as $line) {
            // 1) Import { run } if necessary, right after imports
            if ($inImports && preg_match('/^import .+;/', $line)) {
                $out[] = $line;

                continue;
            }
            if ($inImports) {
                // first non-import line
                if (! str_contains(implode("\n", $out), "from 'vite-plugin-run'")) {
                    $out[] = "import { run } from 'vite-plugin-run';";
                    $haveRunImport = true;
                    $this->info("✅ Added `import { run } from 'vite-plugin-run'`");
                }
                $inImports = false;
            }

            // 2) Detect start of plugins array
            if (preg_match('/plugins\s*:\s*\[/', $line)) {
                $inPlugins = true;
                $out[] = $line;

                continue;
            }

            // 3) If we're in the plugins block and this line closes it, inject snippet first
            if ($inPlugins && preg_match('/^\s*\],/', $line)) {
                // inject the snippet
                foreach ($snippet as $snipLine) {
                    $out[] = $snipLine;
                }
                $inPlugins = false;
            }

            // 4) Always append the original line
            $out[] = $line;
        }

        // 5) Save back
        File::put($viteFile, implode("\n", $out));
        $this->info("✅ Injected inertiaKIT run(...) into plugins array of {$viteFile}");
        $this->line('👉 Don’t forget to install: npm i -D chokidar-cli concurrently vite-plugin-run');
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
