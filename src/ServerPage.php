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
        $this->actions[$name] = $callback;

        return $this;
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

        foreach ($this->actions as $name => $callback) {
            $array[$name] = $callback;
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
