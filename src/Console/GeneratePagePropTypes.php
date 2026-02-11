<?php

namespace InertiaKit\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InertiaKit\Prop;
use InertiaKit\ServerPage;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class GeneratePagePropTypes extends Command
{
    protected $signature = 'inertiakit:page-types';

    protected $description = 'Generate TS interfaces for Inertia page props';

    public function handle()
    {
        $pagesDir = resource_path('js/pages');
        if (! File::isDirectory($pagesDir)) {
            $this->error("Pages directory not found: {$pagesDir}");

            return 1; // Indicate error
        }
        $files = File::allFiles($pagesDir);

        // Always import SharedData; model imports added dynamically
        $importsMeta = [
            'SharedData' => './index', // Adjust if your SharedData lives elsewhere
        ];

        $interfaces = [];

        foreach ($files as $file) {
            // Ensure we only process .server.php files
            if (
                $file->getExtension() !== 'php' ||
                ! Str::endsWith($file->getFilename(), '.server.php')
            ) {
                continue;
            }

            $relativePath = $file->getRelativePathname(); // For logging

            // --- Read file content to parse use statements ---
            $fileContent = File::get($file->getRealPath());
            $usedModels = $this->parseUsedModels($fileContent, $relativePath);
            // --- End file reading ---

            $relativeDir = Str::beforeLast($relativePath, DIRECTORY_SEPARATOR);
            $baseName = Str::replaceLast(
                '.server.php',
                '',
                $file->getFilename()
            );

            // Build interface name e.g. Todos/Index -> TodosIndexProps
            $segments = explode(DIRECTORY_SEPARATOR, $relativeDir);
            $segments[] = $baseName; // Add the base filename
            $pageClassName = implode(
                '',
                array_map(fn ($s) => Str::studly($s), array_filter($segments))
            ); // Filter empty segments if any
            $interfaceName = "{$pageClassName}Props";

            // Isolate require to catch syntax errors etc.
            try {
                // Use an isolated function scope to prevent variable conflicts
                $pageLoader = function ($__path) {
                    return require $__path;
                };
                $result = $pageLoader($file->getRealPath());
            } catch (Throwable $e) {
                $this->warn(
                    "Skipping {$relativePath}: Failed to require file. Error: ".
                        $e->getMessage()
                );

                continue;
            }

            // Check if it's the new ServerPage syntax
            $serverPage = null;
            $page = [];

            if ($result instanceof ServerPage) {
                $serverPage = $result;
                $page = $serverPage->toArray();

                // Check if it has a loader
                if (! $serverPage->getLoader()) {
                    $this->line(
                        "Skipping {$relativePath}: ServerPage has no loader defined.",
                        verbosity: OutputInterface::VERBOSITY_VERBOSE
                    );

                    continue;
                }
            } else {
                $this->warn(
                    "Skipping {$relativePath}: File must return a ServerPage instance."
                );

                continue;
            }

            // --- Get explicit types first (Optional but recommended) ---
            $explicitTypes = $serverPage->getTypes();
            $meta = [];
            $optionalProps = []; // Track which props are optional (defer, optional)
            foreach ($explicitTypes as $key => $typeHint) {
                $propName = Str::camel($key);
                if (Str::endsWith($typeHint, '[]')) {
                    $className = Str::beforeLast($typeHint, '[]');
                    if (
                        class_exists($className) &&
                        is_subclass_of($className, Model::class)
                    ) {
                        $modelName = class_basename($className);
                        $meta[$propName] = "{$modelName}[]";
                        $importsMeta[$modelName] = './models';
                    } else {
                        $this->warn(
                            "Invalid model class in type hint '{$typeHint}' for key '{$key}' in {$relativePath}"
                        );
                        $meta[$propName] = 'any[]';
                    }
                } elseif (
                    class_exists($typeHint) &&
                    is_subclass_of($typeHint, Model::class)
                ) {
                    $modelName = class_basename($typeHint);
                    $meta[$propName] = $modelName;
                    $importsMeta[$modelName] = './models';
                } else {
                    $this->warn(
                        "Invalid model class in type hint '{$typeHint}' for key '{$key}' in {$relativePath}"
                    );
                    $meta[$propName] = 'any';
                }
            }
            // --- End explicit types ---

            // Determine if loader has parameters (route-model binding)
            $loader = $serverPage->getLoader();
            $loaderHasParams = false;
            if ($loader) {
                $loaderRef = new ReflectionFunction($loader);
                foreach ($loaderRef->getParameters() as $param) {
                    $paramType = $param->getType();
                    if ($paramType instanceof ReflectionNamedType && ! $paramType->isBuiltin()) {
                        if (is_subclass_of($paramType->getName(), Model::class)) {
                            $loaderHasParams = true;
                            break;
                        }
                    }
                }
            }

            // Hybrid type generation: parameterized loaders use types() only
            $props = null;
            if ($loaderHasParams) {
                if (empty($explicitTypes)) {
                    $this->warn(
                        "Skipping {$relativePath}: Parameterized loader has no explicit ->types(). Add ->types() to enable type generation."
                    );

                    continue;
                }
                // Use only explicit types, skip runtime execution
                $this->line(
                    "Using explicit types for parameterized loader in {$relativePath}.",
                    verbosity: OutputInterface::VERBOSITY_VERBOSE
                );
            } else {
                try {
                    $props = $loader ? $loader() : [];
                } catch (Throwable $e) {
                    $this->warn(
                        "Skipping {$relativePath}: loader() threw ".
                            get_class($e).
                            ': '.
                            $e->getMessage()
                    );

                    continue;
                }
                if (! is_array($props)) {
                    $this->warn(
                        "Skipping {$relativePath}: load() did not return an array"
                    );

                    continue;
                }

                // Detect or infer model-based props, including Prop instances
                foreach ($props as $key => $value) {
                    $propName = Str::camel($key);

                    // Handle Prop instances
                    if ($value instanceof Prop) {
                        $propType = $value->getType();
                        if (in_array($propType, [Prop::TYPE_DEFER, Prop::TYPE_OPTIONAL])) {
                            $optionalProps[$propName] = true;
                        }

                        // Skip if already handled by explicit types
                        if (isset($meta[$propName])) {
                            continue;
                        }

                        // Resolve the inner callback for type inference
                        try {
                            $innerValue = $value->resolve();
                        } catch (Throwable $e) {
                            $this->warn(
                                "Could not resolve Prop '{$key}' in {$relativePath} for type inference: ".$e->getMessage()
                            );
                            $meta[$propName] = 'unknown';

                            continue;
                        }

                        if ($innerValue instanceof Model) {
                            $modelName = class_basename(get_class($innerValue));
                            $meta[$propName] = $modelName;
                            $importsMeta[$modelName] = './models';
                        } elseif ($innerValue instanceof EloquentCollection) {
                            $first = $innerValue->first();
                            if ($first instanceof Model) {
                                $modelName = class_basename(get_class($first));
                                $meta[$propName] = "{$modelName}[]";
                                $importsMeta[$modelName] = './models';
                            }
                        }
                        // Let it fall through to normalizeForTsInference for non-model types

                        continue;
                    }

                    // Skip if already handled by explicit types
                    if (isset($meta[$propName])) {
                        continue;
                    }

                    if ($value instanceof Model) {
                        $modelName = class_basename(get_class($value));
                        $meta[$propName] = $modelName;
                        $importsMeta[$modelName] = './models';
                    } elseif ($value instanceof EloquentCollection) {
                        $first = $value->first();
                        if ($first instanceof Model) {
                            $modelName = class_basename(get_class($first));
                            $meta[$propName] = "{$modelName}[]";
                            $importsMeta[$modelName] = './models';
                        } else {
                            $singularKey = Str::singular($key);
                            $studlyKey = Str::studly($singularKey);

                            if (isset($usedModels[$studlyKey])) {
                                $modelName = $studlyKey;
                                $meta[$propName] = "{$modelName}[]";
                                $importsMeta[$modelName] = './models';
                                $this->line(
                                    "Inferred type '{$modelName}[]' for empty collection '{$key}' in {$relativePath} based on use statements.",
                                    verbosity: OutputInterface::VERBOSITY_VERBOSE
                                );
                            } else {
                                $this->warn(
                                    "Could not infer type for empty collection '{$key}' in {$relativePath}. Falling back to 'any[]'. Consider adding an explicit type hint or seeding data."
                                );
                            }
                        }
                    }
                }
            }

            // Build the TS interface body
            $body = "export interface {$interfaceName} extends SharedData {\n";

            // Add actions property if there are any actions
            $actions = $serverPage->getActions();
            if (! empty($actions)) {
                $body .= "  actions: {\n";
                foreach ($actions as $actionName => $actionCallback) {
                    $body .= "    {$actionName}: string;\n";
                }
                $body .= "  };\n";
            }

            if ($props !== null) {
                // Normalize for TS inference, resolving Prop instances first
                $resolvedProps = [];
                foreach ($props as $key => $value) {
                    if ($value instanceof Prop) {
                        try {
                            $resolvedProps[$key] = $value->resolve();
                        } catch (Throwable) {
                            $resolvedProps[$key] = null;
                        }
                    } else {
                        $resolvedProps[$key] = $value;
                    }
                }
                $normalizedProps = $this->normalizeForTsInference($resolvedProps);

                foreach ($props as $key => $originalValue) {
                    $propName = Str::camel($key);
                    $isOptional = isset($optionalProps[$propName]);
                    $optionalMarker = $isOptional ? '?' : '';

                    if (isset($meta[$propName])) {
                        $body .= "  {$propName}{$optionalMarker}: {$meta[$propName]};\n";
                    } else {
                        $normalizedValue = $normalizedProps[$key] ?? null;
                        $tsType = $this->inferTsType($normalizedValue);

                        if (
                            $originalValue instanceof EloquentCollection &&
                            $originalValue->isEmpty() &&
                            ! isset($meta[$propName])
                        ) {
                            $body .= "  {$propName}{$optionalMarker}: any[]; // WARN: Could not infer type for empty collection\n";
                        } else {
                            $body .= "  {$propName}{$optionalMarker}: {$tsType};\n";
                        }
                    }
                }
            } else {
                // Parameterized loader — use only explicit types
                foreach ($meta as $propName => $tsType) {
                    $isOptional = isset($optionalProps[$propName]);
                    $optionalMarker = $isOptional ? '?' : '';
                    $body .= "  {$propName}{$optionalMarker}: {$tsType};\n";
                }
            }

            $body .= "  [key: string]: unknown; // Allow additional props\n";
            $body .= "}\n\n";

            $interfaces[] = $body;
        }

        if (empty($interfaces)) {
            $this->info(
                'No page component server files found or processed. No types generated.'
            );

            return 0;
        }

        // Compose final file
        $output = "/**\n";
        $output .=
            " * -----------------------------------------------------------\n";
        $output .=
            " * THIS FILE IS AUTO-GENERATED by `php artisan inertiakit:page-types`\n";
        $output .=
            " * -----------------------------------------------------------\n";
        $output .= " */\n\n";

        // Imports - Sort imports for consistency
        ksort($importsMeta);
        foreach ($importsMeta as $name => $path) {
            // Ensure path uses forward slashes for TS imports
            $importPath = str_replace(DIRECTORY_SEPARATOR, '/', $path);
            $output .= "import type { {$name} } from '{$importPath}';\n";
        }
        $output .= "\n";

        // Interfaces
        $output .= implode('', $interfaces);

        // Write to disk
        $filePath = base_path('resources/js/types/page-props.d.ts'); // Standard location
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, $output);

        $this->info("Generated TypeScript interfaces to: {$filePath}");

        return 0; // Indicate success
    }

    /**
     * Recursively normalize Models and Collections to arrays for TS inference.
     */
    protected function normalizeForTsInference(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $this->normalizeForTsInference($value->toArray());
        } elseif ($value instanceof EloquentCollection) {
            return $this->normalizeForTsInference($value->toArray());
        } elseif (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $subValue) {
                $normalized[$key] = $this->normalizeForTsInference($subValue);
            }

            return $normalized;
        }

        // Return scalar values, null, etc. as is
        return $value;
    }

    /**
     * Parse 'use' statements from PHP code content to find potential Models.
     * Returns an array mapping the base class name (or alias) to the full class name.
     * e.g., ['Todo' => 'App\Models\Todo', 'MyUser' => 'App\Models\User']
     */
    protected function parseUsedModels(
        string $content,
        string $sourceFile
    ): array {
        $used = [];
        // Corrected Regex:
        // - Matches 'use' at the start of a line (m modifier)
        // - Captures the full class name (allowing letters, numbers, _, and \)
        // - Optionally captures an alias
        // - Requires a semicolon at the end
        // Note: Double backslashes \\\\ are needed in the PHP string to represent a single literal backslash \ in the regex pattern.
        $regex =
            "/^use\s+([A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)*)(?:\s+as\s+([A-Za-z0-9_]+))?;/m";

        if (preg_match_all($regex, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullClassName = $match[1];
                $alias = $match[2] ?? null;

                if (class_exists($fullClassName)) {
                    // Use Reflection to ensure it's actually an Eloquent Model subclass
                    try {
                        $reflection = new ReflectionClass($fullClassName);
                        if ($reflection->isSubclassOf(Model::class)) {
                            $baseName =
                                $alias ?? class_basename($fullClassName);
                            if (
                                isset($used[$baseName]) &&
                                $used[$baseName] !== $fullClassName
                            ) {
                                $this->warn(
                                    "Alias/Class name conflict: '{$baseName}' is used for both '{$used[$baseName]}' and '{$fullClassName}' in {$sourceFile}. Inference might be unpredictable."
                                );
                            }
                            $used[$baseName] = $fullClassName;
                        }
                    } catch (Throwable $e) {
                        // Ignore reflection errors (e.g., class not found, though class_exists should prevent this)
                        $this->warn(
                            "Reflection error for class {$fullClassName} in {$sourceFile}: ".
                                $e->getMessage()
                        );
                    }
                } else {
                    // Don't warn for every non-existent class, could be vendor or other types
                    // $this->line("Class '{$fullClassName}' mentioned in use statement in {$sourceFile} but not found or autoloadable.", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                }
            }
        } else {
            // Check for regex errors if matching fails completely
            if (preg_last_error() !== PREG_NO_ERROR) {
                $this->error(
                    'Regex error in parseUsedModels: '.preg_last_error_msg()
                );
            }
        }

        return $used;
    }

    protected function inferTsType(mixed $v): string
    {
        // Add null check first
        if (is_null($v)) {
            return 'null';
        }

        return match (gettype($v)) {
            'boolean' => 'boolean',
            'integer', 'double' => 'number', // 'double' is for float/real
            'string' => 'string',
            'array' => $this->inferArrayType($v),
            // Consider object case? PHP objects without specific classes might become arrays/stdClass
            'object' => 'object', // Generic object, might need refinement
            default => 'unknown', // Use 'unknown' instead of 'any' for better type safety
        };
    }

    protected function inferArrayType(array $arr): string
    {
        if (empty($arr)) {
            // If it was an empty *model* collection, the heuristic should have caught it.
            // This handles regular empty arrays or cases where heuristic failed.
            return 'unknown[]'; // Use unknown[] instead of any[]
        }

        // Check if it's a list (sequential numeric keys starting from 0)
        if (array_is_list($arr)) {
            // Use array_is_list() for PHP 8.1+
            // Infer type from the first element for potentially homogeneous arrays
            // If array has mixed types, this will only reflect the first element's type.
            // A more robust approach would check all elements, but gets complex.
            return $this->inferTsType($arr[0]).'[]';
        }

        // Associative array -> inline object type
        $fields = [];
        foreach ($arr as $k => $v) {
            // Ensure keys are valid TS property names (basic handling for quotes if needed)
            $propKey = preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $k)
                ? $k
                : "'{$k}'";
            $fields[] = "{$propKey}: ".$this->inferTsType($v);
        }
        // Sort fields alphabetically for consistent output order
        sort($fields);

        return '{ '.implode('; ', $fields).' }';
    }
}
