<?php

declare(strict_types=1);

namespace Marrow\Template;

/**
 * Passed to view composers so they can inject data via $view->with('key', $value).
 */
class ViewData
{
    private array $data;

    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function with(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
