<?php

namespace JoshCirre\InertiaKit\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass; // Needed for checking if a class is a Model
use Symfony\Component\Console\Output\OutputInterface; // Import Throwable
use Throwable; // For verbosity levels

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
                $page = $pageLoader($file->getRealPath());
            } catch (Throwable $e) {
                $this->warn(
                    "Skipping {$relativePath}: Failed to require file. Error: ".
                        $e->getMessage()
                );

                continue;
            }

            if (! is_array($page)) {
                $this->warn(
                    "Skipping {$relativePath}: File did not return an array."
                );

                continue;
            }

            if (! isset($page['load']) || ! is_callable($page['load'])) {
                $this->line(
                    "Skipping {$relativePath}: No callable 'load' key found.",
                    verbosity: OutputInterface::VERBOSITY_VERBOSE
                );

                continue;
            }

            // --- Get explicit types first (Optional but recommended) ---
            $explicitTypes = $page['types'] ?? [];
            $meta = [];
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
                        $importsMeta[$modelName] = './models'; // Adjust if models types are elsewhere
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
                    $importsMeta[$modelName] = './models'; // Adjust if models types are elsewhere
                } else {
                    $this->warn(
                        "Invalid model class in type hint '{$typeHint}' for key '{$key}' in {$relativePath}"
                    );
                    $meta[$propName] = 'any';
                }
            }
            // --- End explicit types ---

            try {
                // Execute the load function to get props
                $props = $page['load']();
            } catch (Throwable $e) {
                $this->warn(
                    "Skipping {$relativePath}: load() threw ".
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

            // Detect or infer model-based props
            $propsForInference = []; // Store original values before normalization
            foreach ($props as $key => $value) {
                $propName = Str::camel($key);
                $propsForInference[$propName] = $value; // Store with camelCase key

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
                        // Collection is not empty, direct detection works
                        $modelName = class_basename(get_class($first));
                        $meta[$propName] = "{$modelName}[]";
                        $importsMeta[$modelName] = './models';
                    } else {
                        // --- Heuristic for Empty Collections ---
                        // Try to guess based on prop name and used models
                        $singularKey = Str::singular($key); // e.g., "todos" -> "todo"
                        $studlyKey = Str::studly($singularKey); // e.g., "todo" -> "Todo"

                        if (isset($usedModels[$studlyKey])) {
                            $modelName = $studlyKey; // Use the base name found in use statements
                            $meta[$propName] = "{$modelName}[]";
                            $importsMeta[$modelName] = './models';
                            $this->line(
                                "Inferred type '{$modelName}[]' for empty collection '{$key}' in {$relativePath} based on use statements.",
                                verbosity: OutputInterface::VERBOSITY_VERBOSE
                            );
                        } else {
                            // Cannot infer, fallback needed later
                            $this->warn(
                                "Could not infer type for empty collection '{$key}' in {$relativePath}. Falling back to 'any[]'. Consider adding an explicit type hint or seeding data."
                            );
                        }
                        // --- End Heuristic ---
                    }
                }
                // Optional: Add heuristic for null values that might be unloaded relations
                // elseif ($value === null) {
                //     $studlyKey = Str::studly($key);
                //     if (isset($usedModels[$studlyKey])) {
                //         $modelName = $studlyKey;
                //         $meta[$propName] = "{$modelName} | null"; // Or just ModelName if always expected
                //         $importsMeta[$modelName] = './models';
                //         $this->line("Inferred type '{$modelName} | null' for null value '{$key}' in {$relativePath} based on use statements.", verbosity: OutputInterface::VERBOSITY_VERBOSE);
                //     }
                // }
            }

            // Normalize remaining Models/Collections for basic type inference
            // We need a recursive function that handles the original structure
            $normalizedProps = $this->normalizeForTsInference($props);

            // Build the TS interface body
            $body = "export interface {$interfaceName} extends SharedData {\n";
            // Iterate using the original prop structure keys but use normalized values for inference
            foreach ($props as $key => $originalValue) {
                $propName = Str::camel($key); // Ensure consistency

                if (isset($meta[$propName])) {
                    // Type determined by explicit hint, detection, or heuristic
                    $body .= "  {$propName}: {$meta[$propName]};\n";
                } else {
                    // Fallback to basic inference using the normalized value
                    $normalizedValue = $normalizedProps[$key] ?? $originalValue; // Use normalized value if available
                    $tsType = $this->inferTsType($normalizedValue);

                    // Add a warning comment if we fell back for an empty collection
                    if (
                        $originalValue instanceof EloquentCollection &&
                        $originalValue->isEmpty() &&
                        ! isset($meta[$propName])
                    ) {
                        $body .= "  {$propName}: any[]; // WARN: Could not infer type for empty collection\n";
                    } else {
                        $body .= "  {$propName}: {$tsType};\n";
                    }
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
