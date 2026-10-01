<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Template\Component;
use Twig\Markup;

// ── Fixtures ──────────────────────────────────────────────────────────────

class FixtureCardComponent extends Component
{
    public string $title = '';
    public string $slot = '';

    public function render(): string
    {
        return 'components/card';
    }
}

class FixtureSecretComponent extends Component
{
    public string $label = '';
    private string $secret = 'untouched'; // @phpstan-ignore-line

    public function render(): string
    {
        return 'components/secret';
    }

    public function secret(): string
    {
        return $this->secret;
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────
//
// Component::__construct() used to assign every prop whose key matched any
// property_exists() hit via plain `$this->$key = $value`, with two real
// consequences once exercised end-to-end (both only ever surfaced building
// marrow/ui, since nothing shipped with core itself had used Component
// until then):
//
//  1. A value built via Twig's `{% set x %}...{% endset %}` is a
//     Twig\Markup object, not a plain string. Assigning it to a `public
//     string $slot`-shaped property threw a TypeError — despite that
//     being the documented way to pass multi-line HTML into a component.
//  2. A prop key matching the base class's *static* `$name` property (e.g.
//     a component with its own `name` prop, for an HTML `name=""`
//     attribute) attempted a static write through `$this->name = ...`,
//     which PHP warns about and which doesn't do what was intended anyway.

test('a Twig\Markup value (as produced by {% set %}...{% endset %}) is coerced to a plain string', function () {
    $markup = new Markup('<p>captured</p>', 'UTF-8');

    $component = new FixtureCardComponent(['title' => 'Account', 'slot' => $markup]);

    expect($component->slot)->toBeString();
    expect($component->slot)->toBe('<p>captured</p>');
});

test('a plain string prop still works exactly as before', function () {
    $component = new FixtureCardComponent(['title' => 'Account', 'slot' => '<p>plain</p>']);

    expect($component->slot)->toBe('<p>plain</p>');
});

test('a prop key matching the static $name property is silently skipped, not written', function () {
    // No TypeError/notice, and componentName() still falls back to the
    // class-derived default rather than picking up the passed value.
    $component = new FixtureCardComponent(['name' => 'not-a-real-override']);

    expect($component->render())->toBe('components/card');
    expect(FixtureCardComponent::componentName())->toBe('fixture-card');
});

test('a non-public property is never written through the constructor', function () {
    $component = new FixtureSecretComponent(['label' => 'x', 'secret' => 'hijacked']);

    expect($component->secret())->toBe('untouched');
});

test('an unknown prop key is silently ignored, same as before', function () {
    $component = new FixtureCardComponent(['title' => 'Account', 'nonsense' => 'ignored']);

    expect($component->title)->toBe('Account');
});
