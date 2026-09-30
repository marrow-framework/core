<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Exceptions\ContainerException;
use Marrow\Module\ModuleManager;
use Marrow\Tests\Unit\Fixtures\ConsumerModuleB;
use Marrow\Tests\Unit\Fixtures\ModuleAExportedService;
use Marrow\Tests\Unit\Fixtures\ModuleAPrivateService;
use Marrow\Tests\Unit\Fixtures\ModuleBController;
use Marrow\Tests\Unit\Fixtures\ModuleBServiceNeedingExported;
use Marrow\Tests\Unit\Fixtures\ModuleBServiceNeedingNamedBinding;
use Marrow\Tests\Unit\Fixtures\ModuleBServiceNeedingPrivate;
use Marrow\Tests\Unit\Fixtures\ProviderModuleA;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Exercises Container::make()'s module-context stack via a real
// ModuleManager::boot() (not an isolated Container), so bindingOwners/
// moduleExports are populated exactly as they would be at application boot.

function bootedContainer(): Container
{
    $container = new Container();
    $manager   = new ModuleManager($container);
    $manager->register(ProviderModuleA::class);
    $manager->register(ConsumerModuleB::class);
    $manager->boot();

    return $container;
}

test('un provider privé d\'un autre module reste inatteignable via une dépendance de constructeur', function () {
    $container = bootedContainer();

    expect(fn () => $container->make(ModuleBServiceNeedingPrivate::class))
        ->toThrow(ContainerException::class);
});

test('un provider exporté est atteignable via une dépendance de constructeur', function () {
    $container = bootedContainer();

    $result = $container->make(ModuleBServiceNeedingExported::class);

    expect($result)->toBeInstanceOf(ModuleBServiceNeedingExported::class);
    expect($result->dep)->toBeInstanceOf(ModuleAExportedService::class);
});

test('le contexte de module survit à une chaîne de résolution à 3 niveaux, sans callerModule explicite', function () {
    $container = bootedContainer();

    // Simule ce que ferait Router::callAction() : make() sans callerModule,
    // le contexte s'amorce uniquement via bindingOwners[ModuleBController::class].
    $controller = $container->make(ModuleBController::class);

    expect($controller)->toBeInstanceOf(ModuleBController::class);
    expect($controller->service)->toBeInstanceOf(ModuleBServiceNeedingExported::class);
    expect($controller->service->dep)->toBeInstanceOf(ModuleAExportedService::class);
});

test('un singleton déjà résolu reste protégé au second appel depuis un autre module', function () {
    $container = bootedContainer();

    // Premier accès légitime, depuis le module propriétaire — met en cache.
    $first = $container->make(ModuleAPrivateService::class, callerModule: ProviderModuleA::class);
    expect($first)->toBeInstanceOf(ModuleAPrivateService::class);

    // Second accès, depuis un autre module — doit échouer malgré le cache.
    expect(fn () => $container->make(ModuleAPrivateService::class, callerModule: ConsumerModuleB::class))
        ->toThrow(ContainerException::class);
});

test('#[Inject] sur un named binding respecte aussi l\'isolation de module', function () {
    $container = bootedContainer();
    $container->bind('provider-a.secret', fn () => 'shh', module: ProviderModuleA::class);

    expect(fn () => $container->make(ModuleBServiceNeedingNamedBinding::class))
        ->toThrow(ContainerException::class);
});

test('un binding sans module propriétaire reste accessible depuis n\'importe quel module', function () {
    $container = bootedContainer();
    $container->instance('global.thing', new \stdClass());

    expect($container->make('global.thing', callerModule: ConsumerModuleB::class))
        ->toBeInstanceOf(\stdClass::class);
});

test('la pile de contexte se dépile correctement après une exception, sans fuite vers la résolution suivante', function () {
    $container = bootedContainer();

    expect(fn () => $container->make(ModuleBServiceNeedingPrivate::class))
        ->toThrow(ContainerException::class);

    // Si le contexte avait fuité, cette résolution (sans lien avec la précédente)
    // hériterait à tort d'un module et pourrait échouer ou réussir à tort.
    $result = $container->make(ModuleBServiceNeedingExported::class);
    expect($result->dep)->toBeInstanceOf(ModuleAExportedService::class);
});

test('resetModuleContext vide explicitement la pile', function () {
    $container = bootedContainer();
    $container->resetModuleContext();

    $result = $container->make(ModuleBServiceNeedingExported::class);
    expect($result->dep)->toBeInstanceOf(ModuleAExportedService::class);
});
