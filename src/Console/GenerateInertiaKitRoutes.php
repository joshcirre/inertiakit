<?php

namespace InertiaKit\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InertiaKit\Prop;
use InertiaKit\ServerPage;
use ReflectionFunction;
use ReflectionNamedType;

class GenerateInertiaKitRoutes extends Command
{
    protected $signature = 'inertiakit:generate';

    protected $description = 'Regenerate routes/inertiakit.php and sync Generated/Page controllers';

    public function handle()
    {
        $this->injectRoutesRequire();

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

            // Parse [param] segments for URI and strip from names/classes
            $urlSegs = [];
            $cleanParts = [];
            foreach ($parts as $part) {
                if (preg_match('/^\[(.+)\]$/', $part, $m)) {
                    $urlSegs[] = '{'.$m[1].'}';
                    $cleanParts[] = $m[1];
                } else {
                    $urlSegs[] = $part;
                    $cleanParts[] = $part;
                }
            }

            // URI → drop 'index' at end
            if ($isIndex) {
                array_pop($urlSegs);
            }
            $uri = '/'.implode('/', $urlSegs ?: ['']);

            // routeName → keep 'index' so Wayfinder nests, use clean parts
            $nameSegs = array_map(
                fn ($p) => in_array($p, $reservedJS, true) ? "{$p}_" : $p,
                $cleanParts
            );
            $routeName = implode('.', $nameSegs);

            $component = str_replace(DIRECTORY_SEPARATOR, '/', $base);
            $hasServer = in_array($base, $serverBases, true);

            // Load the server page
            $serverPage = null;
            $pageArr = [];
            $middleware = [];

            if ($hasServer) {
                $serverPageResult = require "{$pagesDir}/{$base}.server.php";

                // Check if it's the new ServerPage syntax
                if ($serverPageResult instanceof ServerPage) {
                    $serverPage = $serverPageResult;
                    $pageArr = $serverPage->toArray();
                    $middleware = $serverPage->getMiddleware();
                } else {
                    throw new \RuntimeException("Server file {$base}.server.php must return a ServerPage instance");
                }
            }

            //
            // ─── Controller stub ───
            //
            $ns = 'App\\Http\\Controllers\\Generated\\Pages';
            $classBase = implode('_', $cleanParts) ?: 'Home';
            $ctrlName = Str::studly($classBase).'Controller';
            $fullClass = "{$ns}\\{$ctrlName}";

            File::ensureDirectoryExists($genCtrlDir);

            // always overwrite stub
            $stub = "<?php\n\nnamespace {$ns};\n\n";
            $stub .= "use App\\Http\\Controllers\\Controller;\n";
            $stub .= "use Illuminate\\Http\\Request;\n";
            $stub .= "use Inertia\\Inertia;\n";
            $stub .= "use InertiaKit\\Prop;\n";

            // Collect model imports from loader and actions
            $modelImports = [];

            // Check loader for route-model binding params
            $loaderParams = [];
            if ($serverPage && $serverPage->getLoader()) {
                $loaderRef = new ReflectionFunction($serverPage->getLoader());
                foreach ($loaderRef->getParameters() as $param) {
                    $paramType = $param->getType();
                    if ($paramType instanceof ReflectionNamedType && ! $paramType->isBuiltin()) {
                        $typeName = $paramType->getName();
                        if (is_subclass_of($typeName, Model::class)) {
                            $loaderParams[] = [
                                'name' => $param->getName(),
                                'type' => $typeName,
                                'short' => class_basename($typeName),
                            ];
                            $modelImports[$typeName] = true;
                        }
                    }
                }
            }

            // Import model classes from actions
            if ($serverPage) {
                foreach ($serverPage->getActions() as $action => $fn) {
                    if (! is_callable($fn)) {
                        continue;
                    }
                    $ref = new ReflectionFunction($fn);
                    foreach ($ref->getParameters() as $param) {
                        $paramType = $param->getType();
                        if ($paramType instanceof ReflectionNamedType && ! $paramType->isBuiltin()) {
                            $typeName = $paramType->getName();
                            if (is_subclass_of($typeName, Model::class)) {
                                $modelImports[$typeName] = true;
                            }
                        }
                    }
                }
            }

            foreach (array_keys($modelImports) as $modelClass) {
                $stub .= "use {$modelClass};\n";
            }

            $stub .= "\nclass {$ctrlName} extends Controller\n{\n";

            // index() — with loader route-model binding params
            $indexSignature = 'public function index(';
            if (! empty($loaderParams)) {
                $paramStrs = array_map(
                    fn ($p) => "{$p['short']} \${$p['name']}",
                    $loaderParams
                );
                $indexSignature .= implode(', ', $paramStrs);
            }
            $indexSignature .= ')';

            $stub .= "    {$indexSignature}\n    {\n";
            if ($hasServer && $serverPage) {
                $stub .= "        \$serverPage = require resource_path('js/pages/{$base}.server.php');\n";
                $stub .= "        \$loader = \$serverPage->getLoader();\n";

                if (! empty($loaderParams)) {
                    $loaderArgs = implode(', ', array_map(fn ($p) => "\${$p['name']}", $loaderParams));
                    $stub .= "        \$rawProps = \$loader ? \$loader({$loaderArgs}) : [];\n";
                } else {
                    $stub .= "        \$rawProps = \$loader ? \$loader() : [];\n";
                }

                // Prop-aware iteration
                $stub .= "        \$props = [];\n";
                $stub .= "        foreach (\$rawProps as \$key => \$value) {\n";
                $stub .= "            if (\$value instanceof Prop) {\n";
                $stub .= "                \$props[\$key] = match(\$value->getType()) {\n";
                $stub .= "                    'defer' => Inertia::defer(\$value->getCallback(), \$value->getGroup()),\n";
                $stub .= "                    'optional' => Inertia::optional(\$value->getCallback()),\n";
                $stub .= "                    'merge' => Inertia::merge(\$value->getCallback()),\n";
                $stub .= "                    'deepMerge' => Inertia::deepMerge(\$value->getCallback()),\n";
                $stub .= "                    'always' => Inertia::always(\$value->getCallback()),\n";
                $stub .= "                    default => (\$value->getCallback())(),\n";
                $stub .= "                };\n";
                $stub .= "            } else {\n";
                $stub .= "                \$props[\$key] = \$value;\n";
                $stub .= "            }\n";
                $stub .= "        }\n";
            } else {
                $stub .= "        \$props = [];\n";
            }
            $stub .= "        \$actions = [];\n";

            // Action route URLs for non-model actions
            if ($serverPage) {
                foreach ($serverPage->getActions() as $action => $fn) {
                    if (! is_callable($fn)) {
                        continue;
                    }
                    $ref = new ReflectionFunction($fn);
                    $hasModelParam = $this->hasModelParameter($ref);
                    if ($hasModelParam) {
                        continue;
                    }
                    $stub .= "        \$actions['{$action}'] = route('{$routeName}.{$action}');\n";
                }
            }

            $stub .= "        \$props['actions'] = \$actions;\n";

            // Get component from ServerPage or use file path
            if ($hasServer && $serverPage) {
                $pageComponent = $serverPage->getComponent();
            } else {
                $pageComponent = $component;
            }

            $stub .= "        return Inertia::render('{$pageComponent}', \$props);\n";
            $stub .= "    }\n\n";

            // each action method
            if ($serverPage) {
                foreach ($serverPage->getActions() as $action => $fn) {
                    if (! is_callable($fn)) {
                        continue;
                    }
                    $ref = new ReflectionFunction($fn);
                    $prm = $ref->getParameters();

                    // Build method signature based on parameter types
                    $sigParts = [];
                    $callParts = [];
                    foreach ($prm as $param) {
                        $paramType = $param->getType();
                        $pn = $param->getName();
                        if ($paramType instanceof ReflectionNamedType && ! $paramType->isBuiltin()) {
                            $typeName = $paramType->getName();
                            if (is_subclass_of($typeName, Model::class)) {
                                $short = class_basename($typeName);
                                $sigParts[] = "{$short} \${$pn}";
                                $callParts[] = "\${$pn}";
                            } elseif ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                                $sigParts[] = "Request \${$pn}";
                                $callParts[] = "\${$pn}";
                            } else {
                                $sigParts[] = "\${$pn}";
                                $callParts[] = "\${$pn}";
                            }
                        } else {
                            $sigParts[] = "\${$pn}";
                            $callParts[] = "\${$pn}";
                        }
                    }

                    $sig = implode(', ', $sigParts);
                    $call = implode(', ', $callParts);

                    $stub .= "    public function {$action}({$sig})\n    {\n";
                    $stub .= "        \$serverPage = require resource_path('js/pages/{$base}.server.php');\n";
                    $stub .= "        \$actions = \$serverPage->getActions();\n";
                    $stub .= "        \$res = \$actions['{$action}']({$call});\n";
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
            // Determine middleware
            $middlewareStr = ! empty($middleware)
                ? "['web', '".implode("', '", $middleware)."']"
                : "'web'";

            // GET index
            $php .= "Route::middleware({$middlewareStr})->get('{$uri}', [{$fullClass}::class,'index'])->name('{$routeName}');\n";

            // action routes
            if ($serverPage) {
                foreach ($serverPage->getRawActions() as $action => $actionData) {
                    $fn = $actionData['callback'];
                    $explicitMethod = $actionData['method'];

                    if (! is_callable($fn)) {
                        continue;
                    }
                    $ref = new ReflectionFunction($fn);
                    $prm = $ref->getParameters();

                    // Determine HTTP method
                    $httpMethod = $explicitMethod ?? $this->detectActionMethod($ref);

                    // Determine action URI
                    $hasModel = $this->hasModelParameter($ref);
                    if ($hasModel) {
                        $modelParam = $this->getModelParameter($ref);
                        $pn = $modelParam->getName();
                        $actionUri = "{$uri}/{$action}/{{$pn}}";
                    } else {
                        $actionUri = "{$uri}/{$action}";
                    }

                    $php .= "Route::middleware({$middlewareStr})->{$httpMethod}('{$actionUri}', [{$fullClass}::class,'{$action}'])->name('{$routeName}.{$action}');\n";
                }
            }
            $php .= "\n";
        }

        File::ensureDirectoryExists(dirname($routesFile));
        File::put($routesFile, $php);
        $this->info("Generated InertiaKit routes at {$routesFile}");

        // 2) Kick off the two type-generation commands
        $this->info('→ Generating page-props types…');
        $this->call('inertiakit:page-types');

        $this->info('→ Generating model types…');
        $this->call('inertiakit:model-types');

        $this->info('All done!');
    }

    /**
     * Detect the HTTP method for an action based on its parameter types.
     * Model only → DELETE, Model + Request → PUT, Request only → POST, else → POST.
     */
    protected function detectActionMethod(ReflectionFunction $ref): string
    {
        $hasModel = false;
        $hasRequest = false;

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeName = $type->getName();
            if (is_subclass_of($typeName, Model::class)) {
                $hasModel = true;
            }
            if ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                $hasRequest = true;
            }
        }

        if ($hasModel && $hasRequest) {
            return 'put';
        }

        if ($hasModel) {
            return 'delete';
        }

        return 'post';
    }

    /**
     * Check if a reflected function has a Model-typed parameter.
     */
    protected function hasModelParameter(ReflectionFunction $ref): bool
    {
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if (
                $type instanceof ReflectionNamedType &&
                ! $type->isBuiltin() &&
                is_subclass_of($type->getName(), Model::class)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the first Model-typed parameter from a reflected function.
     */
    protected function getModelParameter(ReflectionFunction $ref): ?\ReflectionParameter
    {
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if (
                $type instanceof ReflectionNamedType &&
                ! $type->isBuiltin() &&
                is_subclass_of($type->getName(), Model::class)
            ) {
                return $param;
            }
        }

        return null;
    }

    protected function injectRoutesRequire(): void
    {
        // 1) Build the require-statement for whatever routes_file you've configured
        $routeFile = Config::get('inertiakit.routes_file', 'routes/inertiakit.php');
        $webRoutes = base_path('routes/web.php');
        $require = "require __DIR__.'/".basename($routeFile)."';";

        // 2) Bail if file doesn't exist
        if (! File::exists($webRoutes)) {
            $this->warn("routes/web.php not found. Please add manually:\n\n    {$require}\n");

            return;
        }

        // 3) Only append if it's not already there
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
