<?php

namespace JoshCirre\InertiaKit\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionFunction;

class GenerateInertiaKitRoutes extends Command
{
    protected $signature = 'inertiakit:generate';

    protected $description = 'Regenerate routes/inertiakit.php and sync Generated/Page controllers';

    public function handle()
    {
        $pagesDir = resource_path('js/pages');
        $routesFile = base_path(
            Config::get('inertiakit.routes_file', 'routes/inertiakit.php')
        );

        $ignorePatterns = Config::get('inertiakit.ignore', []);

        $genCtrlDir = app_path('Http/Controllers/Generated/Pages');
        $reservedJS = [
            'break',
            'case',
            'class',
            'const',
            'continue',
            'debugger',
            'default',
            'delete',
            'do',
            'else',
            'export',
            'extends',
            'finally',
            'for',
            'function',
            'if',
            'import',
            'in',
            'instanceof',
            'let',
            'new',
            'return',
            'super',
            'switch',
            'this',
            'throw',
            'try',
            'typeof',
            'var',
            'void',
            'while',
            'with',
        ];

        // 1) remove old inertia kit routes & clear cached routes
        if (File::exists($routesFile)) {
            File::delete($routesFile);
            $this->info("Deleted old {$routesFile}");
        }
        Artisan::call('route:clear');
        $this->info('Cleared route cache');

        // 2) collect manual route names + URIs (now that inertia kit is gone)
        $existingNames = collect(Route::getRoutes())
            ->map->getName()
            ->filter()
            ->all();
        $existingUris = collect(Route::getRoutes())
            ->map(fn ($r) => '/'.$r->uri())
            ->unique()
            ->all();

        // 3) discover page bases
        $serverBases = $tsxBases = [];
        foreach (File::allFiles($pagesDir) as $file) {
            $rel = Str::after(
                $file->getRealPath(),
                $pagesDir.DIRECTORY_SEPARATOR
            );
            if (
                $file->getExtension() === 'php' &&
                Str::endsWith($rel, '.server.php')
            ) {
                $serverBases[] = Str::replaceLast('.server.php', '', $rel);
            }
            if ($file->getExtension() === 'tsx') {
                $tsxBases[] = Str::replaceLast('.tsx', '', $rel);
            }
        }
        $allBases = array_unique(array_merge($serverBases, $tsxBases));

        // 4) remove stale controllers
        if (File::isDirectory($genCtrlDir)) {
            foreach (File::files($genCtrlDir) as $f) {
                $fileName = $f->getFilename(); // e.g. TodosIndexController.php
                $classBase = Str::replaceLast('Controller.php', '', $fileName);
                // map StudlyClass to path: snake with '/' delimiter
                $basePath = Str::snake($classBase, '/'); // e.g. 'todos/index'
                if (! in_array($basePath, $allBases, true)) {
                    File::delete($f->getRealPath());
                    $this->info("Deleted stale controller: {$fileName}");
                }
            }
        }

        // 5) begin new inertia-kit routes file
        $php = "<?php\n\n";
        $php .= "use Illuminate\\Support\\Facades\\Route;\n";
        $php .= "use Illuminate\\Http\\Request;\n";
        $php .= "use Inertia\\Inertia;\n\n";

        foreach ($allBases as $base) {
            $parts = explode(DIRECTORY_SEPARATOR, $base);

            // 1) SKIP any folder you manage manually
            foreach ($ignorePatterns as $pattern) {
                // Str::is supports '*' wildcards
                if (Str::is($pattern, $base)) {
                    continue 2; // skips to next $base
                }
            }

            $isIndex = end($parts) === 'index';

            // URI → drop 'index' at end
            $urlSegs = $parts;
            if ($isIndex) {
                array_pop($urlSegs);
            }
            $uri = '/'.implode('/', $urlSegs ?: ['']);

            // routeName → keep 'index' so Wayfinder nests
            $nameSegs = array_map(
                fn ($p) => in_array($p, $reservedJS, true) ? "{$p}_" : $p,
                $parts
            );
            $routeName = implode('.', $nameSegs);

            $component = str_replace(DIRECTORY_SEPARATOR, '/', $base);
            $hasServer = in_array($base, $serverBases, true);
            $pageArr = $hasServer
                ? require "{$pagesDir}/{$base}.server.php"
                : [];

            //
            // ─── Controller stub ───
            //
            $ns = 'App\\Http\\Controllers\\Generated\\Pages';
            $classBase = implode('_', $parts) ?: 'Home';
            $ctrlName = Str::studly($classBase).'Controller';
            $fullClass = "{$ns}\\{$ctrlName}";

            File::ensureDirectoryExists($genCtrlDir);

            // always overwrite stub
            $stub = "<?php\n\nnamespace {$ns};\n\n";
            $stub .= "use App\\Http\\Controllers\\Controller;\n";
            $stub .= "use Illuminate\\Http\\Request;\n";
            $stub .= "use Inertia\\Inertia;\n";
            // import model classes
            foreach ($pageArr as $action => $fn) {
                if ($action === 'load' || ! is_callable($fn)) {
                    continue;
                }
                $ref = new ReflectionFunction($fn);
                $prm = $ref->getParameters();
                if (
                    count($prm) === 1 &&
                    ($t = $prm[0]->getType()) &&
                    is_subclass_of($t->getName(), Model::class)
                ) {
                    $stub .= "use {$t->getName()};\n";
                }
            }
            $stub .= "\nclass {$ctrlName} extends Controller\n{\n";
            // index()
            $stub .= "    public function index()\n    {\n";
            if ($hasServer) {
                $stub .= "        \$page  = require resource_path('js/pages/{$base}.server.php');\n";
                $stub .= "        \$props = (\$page['load'] ?? fn()=>[])();\n";
            } else {
                $stub .= "        \$props = [];\n";
            }
            $stub .= "        \$actions = [];\n";
            // only non-model actions
            foreach ($pageArr as $action => $fn) {
                if ($action === 'load' || ! is_callable($fn)) {
                    continue;
                }
                $ref = new ReflectionFunction($fn);
                $prm = $ref->getParameters();
                $isModel =
                    count($prm) === 1 &&
                    ($t = $prm[0]->getType()) &&
                    is_subclass_of($t->getName(), Model::class);
                if ($isModel) {
                    continue;
                }
                $stub .= "        \$actions['{$action}'] = route('{$routeName}.{$action}');\n";
            }
            $stub .= "        \$props['actions'] = \$actions;\n";
            $stub .= "        return Inertia::render('{$component}', \$props);\n";
            $stub .= "    }\n\n";

            // each action method
            foreach ($pageArr as $action => $fn) {
                if ($action === 'load' || ! is_callable($fn)) {
                    continue;
                }
                $ref = new ReflectionFunction($fn);
                $prm = $ref->getParameters();
                if (
                    count($prm) === 1 &&
                    ($t = $prm[0]->getType()) &&
                    is_subclass_of($t->getName(), Model::class)
                ) {
                    // DELETE + route-model
                    $pn = $prm[0]->getName();
                    $ms = class_basename($t->getName());
                    $stub .= "    public function {$action}({$ms} \${$pn})\n    {\n";
                    $stub .= "        \$page = require resource_path('js/pages/{$base}.server.php');\n";
                    $stub .= "        \$res  = \$page['{$action}'](\${$pn});\n";
                    $stub .=
                        "        return \$res instanceof \\Inertia\\Response ? \$res : redirect()->back();\n";
                    $stub .= "    }\n\n";
                } else {
                    // POST with Request
                    $stub .= "    public function {$action}(Request \$request)\n    {\n";
                    $stub .= "        \$page = require resource_path('js/pages/{$base}.server.php');\n";
                    $stub .= "        \$res  = \$page['{$action}'](\$request);\n";
                    $stub .=
                        "        return \$res instanceof \\Inertia\\Response ? \$res : redirect()->back();\n";
                    $stub .= "    }\n\n";
                }
            }
            $stub .= "}\n";

            File::put("{$genCtrlDir}/{$ctrlName}.php", $stub);
            $this->info("Stubbed controller: {$ctrlName}.php");

            //
            // ─── Routes file ───
            //
            // GET index
            $php .= "Route::middleware('web')->get('{$uri}', [{$fullClass}::class,'index'])->name('{$routeName}');\n";

            // action routes
            foreach ($pageArr as $action => $fn) {
                if ($action === 'load' || ! is_callable($fn)) {
                    continue;
                }
                $ref = new ReflectionFunction($fn);
                $prm = $ref->getParameters();
                if (
                    count($prm) === 1 &&
                    ($t = $prm[0]->getType()) &&
                    is_subclass_of($t->getName(), Model::class)
                ) {
                    $pn = $prm[0]->getName();
                    $php .= "Route::middleware('web')->delete('{$uri}/{{$pn}}', [{$fullClass}::class,'{$action}'])
                          ->name('{$routeName}.{$action}');\n";
                } else {
                    $php .= "Route::middleware('web')->post('{$uri}/{$action}', [{$fullClass}::class,'{$action}'])
                          ->name('{$routeName}.{$action}');\n";
                }
            }
            $php .= "\n";
        }

        File::ensureDirectoryExists(dirname($routesFile));
        File::put($routesFile, $php);
        $this->info("Generated InertiaKit routes at {$routesFile}");

        // 2) Kick off the two type‐generation commands
        $this->info('→ Generating page-props types…');
        $this->call('inertiakit:page-types');

        $this->info('→ Generating model types…');
        $this->call('inertiakit:model-types');

        $this->info('All done!');
    }

    protected function injectRoutesRequire(): void
    {
        // 1) Build the require-statement for whatever routes_file you’ve configured
        $routeFile = Config::get('inertiakit.routes_file', 'routes/inertiakit.php');
        $webRoutes = base_path('routes/web.php');
        $require = "require __DIR__.'/".basename($routeFile)."';";

        // 2) Bail if file doesn’t exist
        if (! File::exists($webRoutes)) {
            $this->warn("routes/web.php not found. Please add manually:\n\n    {$require}\n");

            return;
        }

        // 3) Only append if it’s not already there
        $contents = File::get($webRoutes);
        if (str_contains($contents, $require)) {
            $this->info('✅ routes/web.php already includes your InertiaKit routes');

            return;
        }

        // 4) Append to the end of the file
        File::append($webRoutes, "\n\n{$require}\n");
        $this->info('✅ Appended `'.trim($require).'` to routes/web.php');
    }
}
