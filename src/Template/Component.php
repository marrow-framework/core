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

    public function __construct(array $props = [])
    {
        foreach ($props as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
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
