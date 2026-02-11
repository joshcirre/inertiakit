<?php

namespace InertiaKit;

use Closure;

class Prop
{
    public const TYPE_DEFER = 'defer';

    public const TYPE_OPTIONAL = 'optional';

    public const TYPE_MERGE = 'merge';

    public const TYPE_DEEP_MERGE = 'deepMerge';

    public const TYPE_ALWAYS = 'always';

    private function __construct(
        protected string $type,
        protected Closure $callback,
        protected ?string $group = null,
    ) {}

    public static function defer(Closure $callback): self
    {
        return new self(self::TYPE_DEFER, $callback);
    }

    public static function optional(Closure $callback): self
    {
        return new self(self::TYPE_OPTIONAL, $callback);
    }

    public static function merge(Closure $callback): self
    {
        return new self(self::TYPE_MERGE, $callback);
    }

    public static function deepMerge(Closure $callback): self
    {
        return new self(self::TYPE_DEEP_MERGE, $callback);
    }

    public static function always(Closure $callback): self
    {
        return new self(self::TYPE_ALWAYS, $callback);
    }

    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCallback(): Closure
    {
        return $this->callback;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function resolve(): mixed
    {
        return ($this->callback)();
    }
}
