<?php

declare(strict_types=1);

namespace Marrow\Database\Schema;

use Doctrine\DBAL\Types\Types;

/**
 * Fluent table column/index builder — passed to the callback given to
 * Schema::create()/Schema::table().
 */
class Table
{
    private array $columns = [];
    private array $indices = [];
    private array $foreigns = [];
    private array $drops = [];

    /** @var ForeignIdDefinition[] Awaiting finalizeForeignIds() before getForeigns() is read. */
    private array $pendingForeignIds = [];

    public function __construct(private readonly string $tableName)
    {
    }

    // ─────────────────────── Columns ─────────────────────────────────

    public function id(string $name = 'id'): static
    {
        $this->columns[] = ['name' => $name, 'type' => Types::BIGINT, 'options' => ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]];
        $this->primary($name);
        return $this;
    }

    public function bigIncrements(string $name): static
    {
        return $this->id($name);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::STRING, ['length' => $length, 'notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function text(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::TEXT, ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function integer(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::INTEGER, ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::BIGINT, ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function boolean(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $this->columns[] = $def;
        return $def;
    }

    public function float(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::FLOAT, ['notnull' => true, 'precision' => $precision, 'scale' => $scale]);
        $this->columns[] = $def;
        return $def;
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::DECIMAL, ['notnull' => true, 'precision' => $precision, 'scale' => $scale]);
        $this->columns[] = $def;
        return $def;
    }

    public function json(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::JSON, ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function timestamp(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, 'timestamp', ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function datetime(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::DATETIME_MUTABLE, ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function date(string $name): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::DATE_MUTABLE, ['notnull' => true]);
        $this->columns[] = $def;
        return $def;
    }

    public function timestamps(): static
    {
        $this->datetime('created_at')->nullable();
        $this->datetime('updated_at')->nullable();
        return $this;
    }

    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->datetime($column)->nullable();
        return $this;
    }

    public function foreignId(string $name): ForeignIdDefinition
    {
        $col = $this->unsignedBigInteger($name);
        $def = new ForeignIdDefinition($this, $name, $col);
        $this->pendingForeignIds[] = $def;
        return $def;
    }

    /**
     * Finalizes any foreignId()->constrained() calls made on this table —
     * called by Schema before reading getForeigns(). Needed because
     * ForeignIdDefinition::registerForeign() only adds the FK to $foreigns
     * once called; foreignId() alone doesn't know yet whether constrained()
     * will follow.
     */
    public function finalizeForeignIds(): void
    {
        foreach ($this->pendingForeignIds as $def) {
            $def->registerForeign();
        }
    }

    public function enum(string $name, array $values): ColumnDefinition
    {
        $def = new ColumnDefinition($this, $name, Types::STRING, ['notnull' => true, 'length' => 60]);
        $this->columns[] = $def;
        return $def;
    }

    public function dropColumn(array|string $columns): static
    {
        foreach ((array) $columns as $col) {
            $this->drops[] = $col;
        }
        return $this;
    }

    // ─────────────────────── Indices ─────────────────────────────────

    public function primary(string|array $columns): static
    {
        $this->indices[] = ['type' => 'primary', 'columns' => (array) $columns];
        return $this;
    }

    public function index(string|array $columns, ?string $name = null): static
    {
        $this->indices[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name];
        return $this;
    }

    public function unique(string|array $columns, ?string $name = null): static
    {
        $this->indices[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name];
        return $this;
    }

    public function addForeign(string $column, string $referencedTable, string $referencedColumn = 'id', ?string $onDelete = null): static
    {
        $this->foreigns[] = [
            'column' => $column,
            'table' => $referencedTable,
            'ref_column' => $referencedColumn,
            'on_delete' => $onDelete,
        ];
        return $this;
    }

    // ─────────────────────── Build ────────────────────────────────────

    public function getColumns(): array { return $this->columns; }
    public function getIndices(): array { return $this->indices; }
    public function getForeigns(): array { return $this->foreigns; }
    public function getDrops(): array { return $this->drops; }
    public function getTableName(): string { return $this->tableName; }
}
