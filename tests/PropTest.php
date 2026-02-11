<?php

use InertiaKit\Prop;

it('can create a defer prop', function () {
    $prop = Prop::defer(fn () => ['items' => []]);

    expect($prop)->toBeInstanceOf(Prop::class);
    expect($prop->getType())->toBe(Prop::TYPE_DEFER);
    expect($prop->getGroup())->toBeNull();
});

it('can create a defer prop with a group', function () {
    $prop = Prop::defer(fn () => ['stats' => []])->group('sidebar');

    expect($prop->getType())->toBe(Prop::TYPE_DEFER);
    expect($prop->getGroup())->toBe('sidebar');
});

it('can create an optional prop', function () {
    $prop = Prop::optional(fn () => ['roles' => []]);

    expect($prop->getType())->toBe(Prop::TYPE_OPTIONAL);
});

it('can create a merge prop', function () {
    $prop = Prop::merge(fn () => ['tags' => []]);

    expect($prop->getType())->toBe(Prop::TYPE_MERGE);
});

it('can create a deepMerge prop', function () {
    $prop = Prop::deepMerge(fn () => ['nested' => []]);

    expect($prop->getType())->toBe(Prop::TYPE_DEEP_MERGE);
});

it('can create an always prop', function () {
    $prop = Prop::always(fn () => ['notifications' => 5]);

    expect($prop->getType())->toBe(Prop::TYPE_ALWAYS);
});

it('can resolve a prop callback', function () {
    $prop = Prop::defer(fn () => ['count' => 42]);

    expect($prop->resolve())->toBe(['count' => 42]);
});

it('returns the callback closure', function () {
    $cb = fn () => 'hello';
    $prop = Prop::always($cb);

    expect($prop->getCallback())->toBe($cb);
});

it('group is chainable and returns the same instance', function () {
    $prop = Prop::defer(fn () => []);
    $result = $prop->group('side');

    expect($result)->toBe($prop);
    expect($result->getGroup())->toBe('side');
});

it('has correct type constants', function () {
    expect(Prop::TYPE_DEFER)->toBe('defer');
    expect(Prop::TYPE_OPTIONAL)->toBe('optional');
    expect(Prop::TYPE_MERGE)->toBe('merge');
    expect(Prop::TYPE_DEEP_MERGE)->toBe('deepMerge');
    expect(Prop::TYPE_ALWAYS)->toBe('always');
});
