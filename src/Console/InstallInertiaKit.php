<?php

namespace JoshCirre\InertiaKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallInertiaKit extends Command
{
    protected $signature = 'inertiakit:install';

    protected $description = 'Publish config, wire Vite stub, register InertiaKit routes, and run initial generators';

    protected $signature   = 'inertiakit:install';
    protected $description = 'Publish config, wire Vite stub, register routes, and run initial generators';

    public function handle()
    {
        // 1) Publish the package config
        $this->info('📦 Publishing InertiaKit config...');
        $this->call('vendor:publish', [
            '--provider' => "JoshCirre\\InertiaKit\\InertiaKitServiceProvider",
            '--tag'      => 'config',
        ]);

        // 2) Ensure routes/web.php requires the generated routes file
        $this->injectRoutesRequire();

        // 3) Wire up the Vite plugin stub
        $this->injectVitePlugin();

        // 4) Run the generators
        $this->info('⚙️  Running initial InertiaKit generators…');
        $this->call('inertiakit:generate');

        // finally, install the npm watcher tools
        $this->installNpmDependencies();

        $this->info('🎉 inertiaKIT installation complete!');
    }

    protected function injectRoutesRequire(): void
    {
        $webRoutes = base_path('routes/web.php');
        $require   = "require __DIR__.'/inertiakit.php';";

        if (! File::exists($webRoutes)) {
            $this->warn("routes/web.php not found. Please add:\n\n    {$require}\n manually.");
            return;
        }

        $contents = File::get($webRoutes);
        if (str_contains($contents, $require)) {
            $this->info('⬢ routes/web.php already includes inertiakit.php');
            return;
        }

        // Insert right after <?php
        $newContents = preg_replace(
            '/^<\?php\s*/',
            "<?php\n\n{$require}\n\n",
            $contents
        );

        File::put($webRoutes, $newContents);
        $this->info('✅ Injected route require into routes/web.php');
    }

    protected function injectVitePlugin(): void
    {
        $stubPath  = base_path('vendor/joshcirre/inertiakit/stubs/vite-plugin.stub.js');
        $viteFile  = base_path('vite.config.js');

        if (! File::exists($stubPath)) {
            $this->warn("Vite stub not found at {$stubPath}. Skipping Vite integration.");
            return;
        }

        $stub = File::get($stubPath);

        if (! File::exists($viteFile)) {
            File::put($viteFile, $stub);
            $this->info("✅ Published new vite.config.js stub with InertiaKit plugin.");
        } else {
            $contents = File::get($viteFile);
            if (str_contains($contents, 'inertiakit')) {
                $this->info('⬢ vite.config.js already includes inertiaKIT plugin stub');
            } else {
                File::append($viteFile, "\n\n" . $stub);
                $this->info('✅ Appended inertiaKIT plugin stub to vite.config.js');
            }
        }

        $this->line('👉 Remember to install the npm watcher deps:');
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
