<?php

declare(strict_types=1);

namespace Marrow\Database;

use Marrow\Application;
use Marrow\Database\Attributes\Column;
use Marrow\Database\Attributes\Table;
use Marrow\Database\Relations\BelongsTo;
use Marrow\Database\Relations\BelongsToMany;
use Marrow\Database\Relations\HasMany;
use Marrow\Database\Relations\HasManyThrough;
use Marrow\Database\Relations\HasOne;
use Marrow\Events\Dispatcher;
use Marrow\Support\Collection;
use Marrow\Support\Paginator;
use DateTimeImmutable;
use ReflectionClass;

/**
 * Active Record Model base class.
 * Features: casts, accessors/mutators, dirty tracking, soft deletes (trait),
 * scopes (local + global), events, relations with eager loading.
 */
abstract class Model
{
    // ─────────────────────── Config ──────────────────────────────────

    protected string $table = '';
    protected string $primaryKey = 'id';
    protected bool $incrementing = true;
    protected array $fillable = [];
    protected array $guarded = ['*'];
    protected array $hidden = [];
    protected array $visible = [];
    protected array $casts = [];
    protected array $appends = [];
    protected bool $timestamps = true;
    protected string $createdAt = 'created_at';
    protected string $updatedAt = 'updated_at';

    /** @var array<string, callable> Global scopes applied to every query */
    protected static array $globalScopes = [];

    /** @var array<class-string, true> Classes whose boot{Trait}() hooks have already run. */
    private static array $booted = [];

    /** @var array<class-string, array{table: ?string, fillable: string[], hidden: string[], casts: array<string,string>}> */
    private static array $attributeConfig = [];

    // ─────────────────────── State ───────────────────────────────────

    private array $attributes = [];
    private array $original = [];
    private array $relations = [];
    private bool $exists = false;

    // ─────────────────────── Boot / instantiation ────────────────────

    public function __construct(array $attributes = [])
    {
        static::bootIfNotBooted();
        $this->applyAttributeConfig();
        $this->fill($attributes);
    }

    public static function boot(): void
    {
    }

    /**
     * Runs boot() and every boot{Trait}() hook (e.g. bootSoftDeletes()) for
     * this class, once. Traits register their behavior (like a global scope)
     * from these hooks rather than at class-definition time, since PHP traits
     * have no load-time hook of their own to run code automatically.
     */
    protected static function bootIfNotBooted(): void
    {
        if (isset(self::$booted[static::class])) {
            return;
        }
        self::$booted[static::class] = true;

        static::boot();

        foreach (self::classUsesRecursive(static::class) as $trait) {
            $method = 'boot' . class_basename($trait);
            if (method_exists(static::class, $method)) {
                static::$method();
            }
        }

        self::$attributeConfig[static::class] = self::resolveAttributeConfig(static::class);
    }

    /**
     * Reads #[Table]/#[Column] off the class once per class (cached
     * alongside the boot-once state above) — reflection is comparatively
     * expensive, and this config never changes between instances.
     *
     * @return array{table: ?string, fillable: string[], hidden: string[], casts: array<string,string>}
     */
    private static function resolveAttributeConfig(string $class): array
    {
        $config = ['table' => null, 'fillable' => [], 'hidden' => [], 'casts' => []];
        $reflection = new ReflectionClass($class);

        $tableAttribute = $reflection->getAttributes(Table::class)[0] ?? null;
        if ($tableAttribute !== null) {
            $config['table'] = $tableAttribute->newInstance()->name;
        }

        foreach ($reflection->getAttributes(Column::class) as $columnAttribute) {
            $column = $columnAttribute->newInstance();

            if ($column->fillable) {
                $config['fillable'][] = $column->name;
            }
            if ($column->hidden) {
                $config['hidden'][] = $column->name;
            }
            if ($column->cast !== null) {
                $config['casts'][$column->name] = $column->cast;
            }
        }

        return $config;
    }

    /**
     * Applies the cached #[Table]/#[Column] config onto this instance's
     * $table/$fillable/$hidden/$casts — additively, never overriding an
     * explicit property declaration on the model itself: $table/$fillable
     * only apply if the property was left at its empty default, $hidden
     * merges (both sources can hide different fields), and $casts merges
     * with the explicit array winning on a key collision.
     */
    private function applyAttributeConfig(): void
    {
        $config = self::$attributeConfig[static::class] ?? null;
        if ($config === null) {
            return;
        }

        if ($config['table'] !== null && $this->table === '') {
            $this->table = $config['table'];
        }
        if ($config['fillable'] !== [] && $this->fillable === []) {
            $this->fillable = $config['fillable'];
        }
        if ($config['hidden'] !== []) {
            $this->hidden = array_unique(array_merge($this->hidden, $config['hidden']));
        }
        if ($config['casts'] !== []) {
            $this->casts = array_merge($config['casts'], $this->casts);
        }
    }

    /** @return array<class-string, class-string> Traits used by $class, its parents, and traits-of-traits. */
    private static function classUsesRecursive(string $class): array
    {
        $traits = [];
        foreach ((array_reverse(class_parents($class) ?: []) + [$class => $class]) as $c) {
            $traits += class_uses($c) ?: [];
        }
        foreach ($traits as $trait) {
            $traits += self::traitUsesRecursive($trait);
        }
        return $traits;
    }

    /** @return array<class-string, class-string> */
    private static function traitUsesRecursive(string $trait): array
    {
        $traits = class_uses($trait) ?: [];
        foreach ($traits as $t) {
            $traits += self::traitUsesRecursive($t);
        }
        return $traits;
    }

    protected static function getTable(): string
    {
        $instance = new static();
        if (!empty($instance->table)) {
            return $instance->table;
        }
        // Default: pluralize class basename (very naive, works for English)
        $class = class_basename(static::class);
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $class)) . 's';
    }

    /** @var Connection|null Overridden for test isolation via setConnection() */
    private static ?Connection $testConnection = null;

    /** @var Dispatcher|null Overridden for tests that want to assert on model events via setDispatcher() */
    private static ?Dispatcher $testDispatcher = null;

    public static function setConnection(Connection $connection): void
    {
        self::$testConnection = $connection;
    }

    public static function setDispatcher(?Dispatcher $dispatcher): void
    {
        self::$testDispatcher = $dispatcher;
    }

    protected static function getConnection(): Connection
    {
        if (self::$testConnection !== null) {
            return self::$testConnection;
        }
        return Application::getInstance()->getContainer()->make(Connection::class);
    }

    protected static function getDispatcher(): ?Dispatcher
    {
        if (self::$testDispatcher !== null) {
            return self::$testDispatcher;
        }
        if (self::$testConnection !== null) {
            // Test-isolation mode (setConnection() used, no Application booted)
            // — event dispatch is opt-in here via setDispatcher(), not required.
            return null;
        }
        return Application::getInstance()->getContainer()->make(Dispatcher::class);
    }

    // ─────────────────────── Filling ─────────────────────────────────

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    private function isFillable(string $key): bool
    {
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable, true);
        }
        if ($this->guarded === ['*']) {
            return false;
        }
        return !in_array($key, $this->guarded, true);
    }

    // ─────────────────────── Attributes (get/set) ────────────────────

    public function __get(string $key): mixed
    {
        // Check for accessor
        $accessor = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        // Eager-loaded relation
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Lazy-load relation if method exists
        if (method_exists($this, $key)) {
            $relation = $this->$key();
            if ($relation instanceof \Marrow\Database\Relations\Relation) {
                $result = $relation->getResults();
                $this->relations[$key] = $result;
                return $result;
            }
        }

        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
    }

    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }
        return $this->castGet($key, $this->attributes[$key]);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        // Mutator
        $mutator = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }
        $this->attributes[$key] = $this->castSet($key, $value);
    }

    public function setRawAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    private function castGet(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        if ($cast === null || $value === null) {
            return $value;
        }

        return match (true) {
            $cast === 'int', $cast === 'integer' => (int) $value,
            $cast === 'float', $cast === 'double' => (float) $value,
            $cast === 'bool', $cast === 'boolean' => (bool) $value,
            $cast === 'string' => (string) $value,
            $cast === 'array', $cast === 'json' => is_string($value) ? json_decode($value, true) : $value,
            $cast === 'datetime' => $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable((string) $value),
            str_starts_with($cast, 'encrypted') => $this->decryptCast($value),
            // PHP native enum
            is_subclass_of($cast, \BackedEnum::class) => $cast::from($value),
            // Custom CastsAttributes
            class_exists($cast) && is_a($cast, CastsAttributes::class, true) => (new $cast())->get($this, $key, $value),
            default => $value,
        };
    }

    private function castSet(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        if ($cast === null || $value === null) {
            return $value;
        }

        return match (true) {
            $cast === 'array', $cast === 'json' => json_encode($value),
            $cast === 'datetime' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            str_starts_with($cast, 'encrypted') => $this->encryptCast($value),
            is_object($value) && $value instanceof \BackedEnum => $value->value,
            class_exists($cast) && is_a($cast, CastsAttributes::class, true) => (new $cast())->set($this, $key, $value),
            default => $value,
        };
    }

    /**
     * Backed by Marrow\Support\Crypto (AES-256-GCM, authenticated). Throws
     * if APP_KEY is not configured — a prior version of this cast silently
     * stored/read the value in plaintext instead, which is equivalent to
     * not encrypting the column at all.
     */
    private function decryptCast(string $value): string
    {
        return \Marrow\Support\Crypto::decrypt($value);
    }

    private function encryptCast(string $value): string
    {
        return \Marrow\Support\Crypto::encrypt($value);
    }

    // ─────────────────────── Dirty tracking ──────────────────────────

    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
        }
        return !empty($this->getChanges());
    }

    public function getChanges(): array
    {
        $changes = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $changes[$key] = $value;
            }
        }
        return $changes;
    }

    public function getOriginal(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->original[$key] ?? null;
        }
        return $this->original;
    }

    // ─────────────────────── CRUD ────────────────────────────────────

    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }
        return $this->performInsert();
    }

    private function performInsert(): bool
    {
        if (!$this->fireEvent('creating')) {
            return false;
        }

        $data = $this->getDirtyForWrite();

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            $data[$this->createdAt] = $now;
            $data[$this->updatedAt] = $now;
        }

        $id = static::getConnection()->insert(static::getTable(), $data);

        if ($this->incrementing) {
            $this->attributes[$this->primaryKey] = $id;
        }

        $this->original = $this->attributes;
        $this->exists = true;

        $this->fireEvent('created');
        return true;
    }

    private function performUpdate(): bool
    {
        if (!$this->isDirty()) {
            return true;
        }

        if (!$this->fireEvent('updating')) {
            return false;
        }

        $data = $this->getDirtyForWrite();

        if ($this->timestamps) {
            $data[$this->updatedAt] = date('Y-m-d H:i:s');
        }

        static::getConnection()->update(
            static::getTable(),
            $data,
            [$this->primaryKey => $this->getKey()]
        );

        $this->original = array_merge($this->original, $this->attributes);

        $this->fireEvent('updated');
        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (!$this->fireEvent('deleting')) {
            return false;
        }

        static::getConnection()->delete(static::getTable(), [$this->primaryKey => $this->getKey()]);
        $this->exists = false;

        $this->fireEvent('deleted');
        return true;
    }

    public function refresh(): static
    {
        $fresh = static::find($this->getKey());
        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
            $this->relations = [];
        }
        return $this;
    }

    public function replicate(): static
    {
        $clone = clone $this;
        unset($clone->attributes[$this->primaryKey]);
        $clone->original = [];
        $clone->exists = false;
        return $clone;
    }

    // ─────────────────────── Static CRUD ─────────────────────────────

    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->where(static::make()->primaryKey, $id)->first();
    }

    /** @throws \Marrow\Exceptions\HttpException 404 if no row matches $id. */
    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new \Marrow\Exceptions\HttpException(404, static::class . ' not found.');
        }
        return $model;
    }

    public static function findMany(array $ids): Collection
    {
        return static::query()->whereIn(static::make()->primaryKey, $ids)->get();
    }

    public static function first(): ?static
    {
        return static::query()->first();
    }

    /** @throws \Marrow\Exceptions\HttpException 404 if the query has no results. */
    public static function firstOrFail(): static
    {
        $model = static::first();
        if ($model === null) {
            throw new \Marrow\Exceptions\HttpException(404, static::class . ' not found.');
        }
        return $model;
    }

    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        return self::firstWhere($attributes) ?? self::createOrRecoverFromConflict($attributes, $values);
    }

    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = self::firstWhere($attributes) ?? self::createOrRecoverFromConflict($attributes, $values);
        $model->fill($values)->save();
        return $model;
    }

    private static function firstWhere(array $attributes): ?static
    {
        $q = static::query();
        foreach ($attributes as $k => $v) {
            $q->where($k, $v);
        }
        return $q->first();
    }

    /**
     * The read-then-write in firstOrCreate()/updateOrCreate() has a race
     * window between the SELECT and the INSERT: two concurrent calls can
     * both find nothing and both attempt to create the row. This cannot be
     * made atomic from application code alone — it requires a unique
     * constraint on $attributes at the database level. Given one, the
     * loser's INSERT fails with a UniqueConstraintViolationException
     * instead of silently creating a duplicate row; that failure is caught
     * here and resolved by re-reading the row the winner just created,
     * rather than crashing the request. Without such a constraint, this
     * still can't prevent duplicates — the same limitation Laravel's
     * equivalent methods have.
     */
    private static function createOrRecoverFromConflict(array $attributes, array $values): static
    {
        try {
            return static::create(array_merge($attributes, $values));
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            $model = self::firstWhere($attributes);
            if ($model === null) {
                throw $e;
            }
            return $model;
        }
    }

    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function upsert(array $values, array $uniqueBy): void
    {
        foreach ($values as $data) {
            $criteria = array_intersect_key($data, array_flip($uniqueBy));
            static::updateOrCreate($criteria, $data);
        }
    }

    // ─────────────────────── Query ───────────────────────────────────

    /** @param string[] $withoutGlobalScopes Names of global scopes (as registered via addGlobalScope()) to skip. */
    public static function query(array $withoutGlobalScopes = []): ModelQueryBuilder
    {
        static::bootIfNotBooted();
        return new ModelQueryBuilder(static::getConnection(), static::getTable(), static::class, $withoutGlobalScopes);
    }

    /** Shortcut to start a query with eager-loads. */
    public static function with(string|array ...$relations): ModelQueryBuilder
    {
        $rels = is_array($relations[0] ?? null) ? $relations[0] : $relations;
        return static::query()->with(...$rels);
    }

    // ─────────────────────── Scopes ──────────────────────────────────

    public static function addGlobalScope(string $name, callable $scope): void
    {
        static::$globalScopes[static::class][$name] = $scope;
    }

    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    // ─────────────────────── Relations ───────────────────────────────

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? static::getTable() . '_id';
        $localKey = $localKey ?? $this->primaryKey;
        return new HasOne(static::getConnection(), $instance, $foreignKey, $localKey, $this->{$localKey});
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? static::getTable() . '_id';
        $localKey = $localKey ?? $this->primaryKey;
        return new HasMany(static::getConnection(), $instance, $foreignKey, $localKey, $this->{$localKey});
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? strtolower(class_basename($related)) . '_id';
        $ownerKey = $ownerKey ?? $instance->primaryKey;
        return new BelongsTo(static::getConnection(), $instance, $foreignKey, $ownerKey, $this->getAttribute($foreignKey));
    }

    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null
    ): BelongsToMany {
        $instance = new $related();
        $tables = [static::getTable(), $instance->getTableName()];
        sort($tables);
        $pivotTable = $pivotTable ?? implode('_', $tables);
        $foreignPivotKey = $foreignPivotKey ?? strtolower(class_basename(static::class)) . '_id';
        $relatedPivotKey = $relatedPivotKey ?? strtolower(class_basename($related)) . '_id';
        return new BelongsToMany(
            static::getConnection(),
            $instance,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $this->getKey()
        );
    }

    protected function hasManyThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): HasManyThrough {
        $relatedInstance = new $related();
        $throughInstance = new $through();
        $firstKey = $firstKey ?? strtolower(class_basename(static::class)) . '_id';
        $secondKey = $secondKey ?? strtolower(class_basename($through)) . '_id';
        $localKey = $localKey ?? $this->primaryKey;
        $secondLocalKey = $secondLocalKey ?? $throughInstance->primaryKey;

        return new HasManyThrough(
            static::getConnection(),
            $relatedInstance,
            $throughInstance,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey,
            $this->{$localKey}
        );
    }

    // ─────────────────────── Events ──────────────────────────────────

    /**
     * Fire a model event. Returns false if a listener cancelled it.
     */
    protected function fireEvent(string $event): bool
    {
        $dispatcher = static::getDispatcher();
        if ($dispatcher === null) {
            return true;
        }

        $eventClass = 'Marrow\\Events\\Model\\' . ucfirst($event);
        if (!class_exists($eventClass)) {
            return true;
        }

        return (bool) $dispatcher->until(new $eventClass($this));
    }

    // ─────────────────────── Serialization ───────────────────────────

    /**
     * @return array<string, mixed> Visible/non-hidden attributes (cast), plus
     *         appended virtual attributes and any eager-loaded relations.
     */
    public function toArray(): array
    {
        $result = [];
        $attrs = $this->attributes;

        // Apply visible/hidden filters
        if (!empty($this->visible)) {
            $attrs = array_intersect_key($attrs, array_flip($this->visible));
        }
        if (!empty($this->hidden)) {
            $attrs = array_diff_key($attrs, array_flip($this->hidden));
        }

        foreach ($attrs as $key => $value) {
            $result[$key] = $this->getAttribute($key);
        }

        // Appended virtual attributes
        foreach ($this->appends as $key) {
            $result[$key] = $this->__get($key);
        }

        // Relations
        foreach ($this->relations as $key => $rel) {
            $result[$key] = $rel instanceof Collection ? $rel->toArray()
                : ($rel instanceof Model ? $rel->toArray() : $rel);
        }

        return $result;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }

    // ─────────────────────── Helpers ─────────────────────────────────

    public function getKey(): int|string
    {
        return $this->attributes[$this->primaryKey] ?? 0;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getTableName(): string
    {
        return static::getTable();
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function setExists(bool $exists): void
    {
        $this->exists = $exists;
    }

    public function setOriginal(array $original): void
    {
        $this->original = $original;
    }

    public function setRelation(string $key, mixed $value): void
    {
        $this->relations[$key] = $value;
    }

    public function getRawAttributes(): array
    {
        return $this->attributes;
    }

    public function setRawAttributes(array $attrs): void
    {
        $this->attributes = $attrs;
    }

    private function getDirtyForWrite(): array
    {
        if (!$this->exists) {
            return $this->attributes;
        }
        return $this->getChanges();
    }

    protected static function make(): static
    {
        return new static();
    }
}
