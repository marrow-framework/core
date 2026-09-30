<?php

declare(strict_types=1);

namespace Marrow\Database\Schema;

/**
 * Fluent column definition for Table.
 */
class ColumnDefinition
{
    private array $options;

    public function __construct(
        private readonly Table $table,
        public readonly string $name,
        public readonly string $type,
        array $options = []
    ) {
        $this->options = $options;
    }

    public function nullable(bool $value = true): static
    {
        $this->options['notnull'] = !$value;
        return $this;
    }

    public function default(mixed $value): static
    {
        $this->options['default'] = $value;
        return $this;
    }

    public function unsigned(): static
    {
        $this->options['unsigned'] = true;
        return $this;
    }

    public function length(int $length): static
    {
        $this->options['length'] = $length;
        return $this;
    }

    public function unique(): static
    {
        $this->table->unique($this->name);
        return $this;
    }

    public function index(): static
    {
        $this->table->index($this->name);
        return $this;
    }

    /** Set DEFAULT CURRENT_TIMESTAMP (no quoting applied). */
    public function useCurrent(): static
    {
        $this->options['default']     = 'CURRENT_TIMESTAMP';
        $this->options['use_current'] = true;
        return $this;
    }

    /** MySQL AFTER <column> clause when adding a column (silently ignored on other dialects). */
    public function after(string $column): static
    {
        $this->options['after'] = $column;
        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
