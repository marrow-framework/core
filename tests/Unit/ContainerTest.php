<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Exceptions\ContainerException;
use Marrow\Tests\Unit\Fixtures\ServiceWithDep;
use Marrow\Tests\Unit\Fixtures\SimpleService;
use stdClass;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->container = new Container();
});

test('bind and make closure', function () {
    $this->container->bind('greeting', fn () => 'Hello Marrow');
    expect($this->container->make('greeting'))->toBe('Hello Marrow');
});

test('singleton returns same instance', function () {
    $this->container->singleton('counter', fn () => new stdClass());
    $a = $this->container->make('counter');
    $b = $this->container->make('counter');
    expect($a)->toBe($b);
});

test('instance binds existing object', function () {
    $obj = new stdClass();
    $obj->value = 42;
    $this->container->instance('thing', $obj);
    expect($this->container->make('thing'))->toBe($obj);
});

test('auto resolve concrete class', function () {
    expect($this->container->make(SimpleService::class))->toBeInstanceOf(SimpleService::class);
});

test('auto resolve with constructor injection', function () {
    $result = $this->container->make(ServiceWithDep::class);
    expect($result)->toBeInstanceOf(ServiceWithDep::class);
    expect($result->dep)->toBeInstanceOf(SimpleService::class);
});

test('make overrides applied', function () {
    $override = new SimpleService();
    $result   = $this->container->make(ServiceWithDep::class, [SimpleService::class => $override]);
    expect($result->dep)->toBe($override);
});

test('bind overwrites previous', function () {
    $this->container->bind('val', fn () => 1);
    $this->container->bind('val', fn () => 2);
    expect($this->container->make('val'))->toBe(2);
});

test('non-existent abstract throws ContainerException', function () {
    expect(fn () => $this->container->make('NonExistentClass_XYZ_ABC'))
        ->toThrow(ContainerException::class);
});
