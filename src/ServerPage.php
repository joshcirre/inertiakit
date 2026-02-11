<?php

namespace InertiaKit;

use Closure;

class ServerPage
{
    protected string $component;

    protected ?Closure $loaderCallback = null;

    protected array $actions = [];

    protected array $middleware = [];

    protected array $types = [];

    public function __construct(string $component)
    {
        $this->component = $component;
    }

    public static function make(string $component): static
    {
        return new static($component);
    }

    public function middleware(string|array $middleware): static
    {
        $this->middleware = is_array($middleware) ? $middleware : [$middleware];

        return $this;
    }

    public function loader(Closure $callback): static
    {
        $this->loaderCallback = $callback;

        return $this;
    }

    public function action(string $name, Closure $callback): static
    {
        $this->actions[$name] = ['callback' => $callback, 'method' => null];

        return $this;
    }

    public function post(string $name, Closure $callback): static
    {
        $this->actions[$name] = ['callback' => $callback, 'method' => 'post'];

        return $this;
    }

    public function put(string $name, Closure $callback): static
    {
        $this->actions[$name] = ['callback' => $callback, 'method' => 'put'];

        return $this;
    }

    public function patch(string $name, Closure $callback): static
    {
        $this->actions[$name] = ['callback' => $callback, 'method' => 'patch'];

        return $this;
    }

    public function delete(string $name, Closure $callback): static
    {
        $this->actions[$name] = ['callback' => $callback, 'method' => 'delete'];

        return $this;
    }

    public function getActionCallback(string $name): ?Closure
    {
        return $this->actions[$name]['callback'] ?? null;
    }

    public function getActionMethod(string $name): ?string
    {
        return $this->actions[$name]['method'] ?? null;
    }

    public function types(array $types): static
    {
        $this->types = $types;

        return $this;
    }

    public function getComponent(): string
    {
        return $this->component;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getLoader(): ?Closure
    {
        return $this->loaderCallback;
    }

    public function getActions(): array
    {
        return array_map(
            fn (array $action) => $action['callback'],
            $this->actions
        );
    }

    public function getRawActions(): array
    {
        return $this->actions;
    }

    public function getTypes(): array
    {
        return $this->types;
    }

    public function toArray(): array
    {
        $array = [];

        if ($this->loaderCallback !== null) {
            $array['load'] = $this->loaderCallback;
        }

        foreach ($this->actions as $name => $action) {
            $array[$name] = $action['callback'];
        }

        if (! empty($this->types)) {
            $array['types'] = $this->types;
        }

        if (! empty($this->middleware)) {
            $array['middleware'] = $this->middleware;
        }

        if (! empty($this->component)) {
            $array['component'] = $this->component;
        }

        return $array;
    }

    public function toResponse(): array
    {
        return $this->toArray();
    }
}
