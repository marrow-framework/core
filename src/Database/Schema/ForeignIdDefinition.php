<?php

declare(strict_types=1);

namespace Marrow\Database\Schema;

/**
 * Fluent foreign key builder: $t->foreignId('user_id')->constrained()->cascadeOnDelete()
 */
class ForeignIdDefinition
{
    private ?string $referencedTable = null;
    private string $referencedColumn = 'id';
    private ?string $onDelete = null;

    public function __construct(
        private readonly Table $table,
        private readonly string $column,
        private readonly ColumnDefinition $colDef
    ) {
    }

    public function constrained(?string $onTable = null, string $column = 'id'): static
    {
        // Guess table from column name: user_id → users
        $this->referencedTable = $onTable ?? rtrim(str_replace('_id', '', $this->column), '_') . 's';
        $this->referencedColumn = $column;
        return $this;
    }

    public function cascadeOnDelete(): static
    {
        $this->onDelete = 'CASCADE';
        return $this;
    }

    public function nullOnDelete(): static
    {
        $this->onDelete = 'SET NULL';
        return $this;
    }

    public function nullable(bool $v = true): static
    {
        $this->colDef->nullable($v);
        return $this;
    }

    public function registerForeign(): void
    {
        if ($this->referencedTable) {
            $this->table->addForeign(
                $this->column,
                $this->referencedTable,
                $this->referencedColumn,
                $this->onDelete
            );
        }
    }
}
