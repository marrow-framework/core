<?php

declare(strict_types=1);

namespace Marrow\Template;

use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for view components.
 *
 * A component couples a PHP class (props + logic) to a Twig template.
 * Register the class in a module's boot() or in a service provider,
 * then call {{ component('alert', {type: 'success', message: 'Saved!'}) }} in Twig.
 *
 * Example:
 *   class AlertComponent extends Component {
 *       public string $type    = 'info';
 *       public string $message = '';
 *       public function render(): string { return 'components/alert'; }
 *   }
 *
 *   Template: resources/views/components/alert.html.twig
 */
abstract class Component
{
    /**
     * Override to set a custom component name (kebab-case).
     * Auto-derived from the class name if empty.
     */
    public static string $name = '';

    /**
     * @throws \ReflectionException Never in practice — $this is always a
     *         constructed object of a real class by the time this runs.
     */
    public function __construct(array $props = [])
    {
        $reflection = new ReflectionClass($this);

        foreach ($props as $key => $value) {
            if (!$reflection->hasProperty($key)) {
                continue;
            }

            $property = $reflection->getProperty($key);

            // Skip static properties — property_exists() alone (the
            // previous check here) doesn't distinguish them from instance
            // properties, so a prop sharing a name with the static $name
            // above (e.g. a component with its own `name` prop, matching
            // an HTML `name=""` attribute) would attempt `$this->name = ...`
            // on what is actually a *static* property, which PHP warns
            // about and which silently does the wrong thing regardless.
            // Also skip non-public properties, matching data()'s own
            // IS_PUBLIC-only reflection below — a prop should only ever
            // reach a property this constructor (and Twig) can both see.
            if ($property->isStatic() || !$property->isPublic()) {
                continue;
            }

            // A value built via Twig's `{% set x %}...{% endset %}` (the
            // documented way to pass multi-line HTML into a `slot`-like
            // prop) is a Twig\Markup object, not a plain string — and PHP
            // does not implicitly coerce an object, Stringable or not, into
            // a typed `string` property. Every component here with a
            // `public string $slot` (or similar) would otherwise throw a
            // TypeError the moment it's called with captured Twig content
            // instead of a literal PHP string.
            $type = $property->getType();
            if ($value instanceof \Stringable
                && $type instanceof \ReflectionNamedType
                && $type->getName() === 'string'
            ) {
                $value = (string) $value;
            }

            $this->$key = $value;
        }
    }

    /**
     * Returns the Twig template name to render.
     * Example: 'components/alert' → resources/views/components/alert.html.twig
     */
    abstract public function render(): string;

    /**
     * Returns the data (variables) available inside the component template.
     * By default exposes all public non-static properties.
     */
    public function data(): array
    {
        $reflection = new ReflectionClass($this);
        $data = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isStatic() && $prop->isInitialized($this)) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }
        return $data;
    }

    /**
     * Derive the component name from the class (FooBarComponent → foo-bar).
     */
    public static function componentName(): string
    {
        if (static::$name !== '') {
            return static::$name;
        }
        $short = class_basename(static::class);
        $short = preg_replace('/Component$/', '', $short) ?? $short;
        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $short));
    }
}
