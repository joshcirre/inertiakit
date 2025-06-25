# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

InertiaKit is a Laravel package that provides zero-boilerplate file-based routing and TypeScript type generation for InertiaJS applications. It enables Laravel developers to build modern SSR/SPA applications with full TypeScript support.

## Key Architecture

### File-Based Routing System
- Routes are automatically generated from `resources/js/pages/*.server.php` files
- Each `.server.php` file must export a page component path and optional props function
- Generated routes are stored in `routes/inertia.php`

### TypeScript Generation
1. **Model Types**: Generates TypeScript interfaces from Eloquent models
2. **Page Props**: Automatically types page component props with camelCased keys
3. **Generated files** are placed in `resources/js/types/`

### Core Components
- `src/Commands/`: Artisan commands for installation and generation
- `src/InertiaKitServiceProvider.php`: Package service provider
- `config/inertiakit.php`: Configuration file (model directories, namespace settings)

## Development Commands

```bash
# Testing & Quality
composer test          # Run full test suite (refactor, lint, types, unit tests)
composer test:unit     # Run unit tests only
composer test:lint     # Check code style (Laravel Pint)
composer test:types    # Run PHPStan type checking
composer test:refacto  # Check Rector refactoring suggestions

# Code Fixes
composer lint          # Fix code style issues
composer refacto       # Apply Rector refactoring

# Package Commands (when installed in Laravel app)
php artisan inertiakit:install      # Initial setup
php artisan inertiakit:generate     # Generate routes from .server.php files
php artisan inertiakit:model-types  # Generate TypeScript types for models
php artisan inertiakit:page-types   # Generate TypeScript types for page props
```

## Code Standards

- **PHP Version**: ^8.1.0
- **Laravel Support**: 10.x, 11.x, 12.x
- **Code Style**: Laravel Pint (PSR-12 based)
- **Type Safety**: PHPStan at max level
- **Testing**: PestPHP framework

## Working with File-Based Routes

Example `.server.php` file structure:
```php
<?php

use App\Models\User;

return [
    'component' => 'Users/Index',
    'props' => fn() => [
        'users' => User::all()
    ]
];
```

This automatically creates a route and TypeScript types for the component props.