<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Exceptions\ModuleException;
use Marrow\Module\ModuleManager;
use Marrow\Tests\Unit\Fixtures\AlphaModule;
use Marrow\Tests\Unit\Fixtures\BetaModule;
use Marrow\Tests\Unit\Fixtures\DeltaModule;
use Marrow\Tests\Unit\Fixtures\EpsilonModule;
use Marrow\Tests\Unit\Fixtures\GammaModule;

// ── Tests ─────────────────────────────────────────────────────────────────────

function makeManager(): ModuleManager
{
    return new ModuleManager(new Container());
}

test('register and boot single module', function () {
    $manager = makeManager();
    $manager->register(AlphaModule::class);
    $manager->boot();
    expect(true)->toBeTrue();
});

test('dependency boot order respected', function () {
    $manager = makeManager();
    $manager->register(GammaModule::class);
    $manager->register(BetaModule::class);
    $manager->register(AlphaModule::class);
    $manager->boot();

    $order = $manager->getBootOrder();
    $alpha = array_search(AlphaModule::class, $order, true);
    $beta  = array_search(BetaModule::class,  $order, true);
    $gamma = array_search(GammaModule::class,  $order, true);

    expect($alpha)->toBeLessThan($beta);
    expect($beta)->toBeLessThan($gamma);
});

test('cycle throws ModuleException', function () {
    $manager = makeManager();
    $manager->register(DeltaModule::class);
    $manager->register(EpsilonModule::class);

    expect(fn () => $manager->boot())
        ->toThrow(ModuleException::class);
});

test('missing import throws ModuleException', function () {
    $manager = makeManager();
    $manager->register(BetaModule::class); // imports Alpha but Alpha not registered

    expect(fn () => $manager->boot())
        ->toThrow(ModuleException::class);
});

test('renderGraph returns string containing module names', function () {
    $manager = makeManager();
    $manager->register(AlphaModule::class);
    $manager->register(BetaModule::class);
    $manager->register(GammaModule::class);
    $manager->boot();

    $graph = $manager->renderGraph();
    expect($graph)->toBeString();
    expect($graph)->toContain('alpha');
    expect($graph)->toContain('beta');
});
