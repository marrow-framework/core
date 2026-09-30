# Database & ORM

`Marrow\Database\Connection` wraps a single Doctrine DBAL connection —
there is no multi-connection manager, so `config/database.php` *is* the
connection's parameters (`driver`, `database`, plus `host`/`port`/`username`/
`password`/`charset` for MySQL/PostgreSQL). See [Configuration](configuration.md).

## `Connection` — raw access

```php
$db = app(\Marrow\Database\Connection::class);

$rows = $db->select('SELECT * FROM posts WHERE published = ?', [1]);
$row  = $db->selectOne('SELECT * FROM posts WHERE id = ?', [$id]);
$db->statement('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
$id   = $db->insert('posts', ['title' => 'Hello']);
$db->update('posts', ['title' => 'Updated'], ['id' => $id]);
$db->delete('posts', ['id' => $id]);

$db->transaction(function (Connection $tx) {
    $tx->insert('posts', [...]);
    $tx->insert('audit_logs', [...]);
});
```

`$db->table('posts')` returns a `QueryBuilder` (see below) for anything
beyond raw SQL that doesn't need full `Model` hydration.

## `QueryBuilder` — fluent SQL

```php
$db->table('posts')
    ->where('published', true)
    ->where('views', '>', 100)
    ->orWhere('featured', true)
    ->whereIn('category_id', [1, 2, 3])
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();               // Collection of stdClass
```

Conditions: `where`, `orWhere`, `whereIn`/`whereNotIn`, `whereNull`/`whereNotNull`,
`whereBetween`, `whereLike`, `whereExists(subqueryCallback)`, `when($cond, $cb, $else)`.
Joins: `join`/`leftJoin`. Ordering/grouping: `orderBy`, `latest`/`oldest`,
`groupBy`, `having`. Limits: `limit`/`take`, `offset`/`skip`, `forPage($page, $perPage)`.
Fetching: `get()`, `first()`, `find($id)`, `count()`, `sum()`, `avg()`,
`min()`, `max()`, `pluck($column)`, `chunk($size, $callback)`, `paginate($perPage, $page)`.
Writes: `insertGetId()`, `insertMany()`, `updateWhere()`, `deleteWhere()`.
`toSql()` returns `[$sql, $bindings]` for debugging.

## `Model` — Active Record

```php
class Post extends Model
{
    protected string $table = 'posts';           // default: snake_case plural of class name
    protected array $fillable = ['title', 'body'];
    protected array $hidden = ['internal_notes'];
    protected array $casts = [
        'published' => 'bool',
        'meta' => 'json',
        'published_at' => 'datetime',
    ];
    protected bool $timestamps = true;             // created_at / updated_at, on by default
}
```

### `#[Table]` / `#[Column]` — the same config, as attributes

An alternative to the arrays above:

```php
use Marrow\Database\Attributes\Column;
use Marrow\Database\Attributes\Table;

#[Table('posts')]
#[Column('title')]
#[Column('body')]
#[Column('internal_notes', fillable: false, hidden: true)]
#[Column('published', cast: 'bool')]
#[Column('published_at', cast: 'datetime')]
class Post extends Model
{
}
```

Deliberately class-level and repeatable, never property-level: a `Model`'s
fields are virtual (stored in an internal array, read/written through
`__get()`/`__set()`) — a real declared property with the same name (e.g.
`public string $title`) would shadow those magic methods entirely, so
`$post->title` would read an uninitialized native property instead of ever
reaching the model's own storage. There is deliberately nothing to attach
a property attribute to here.

Merges with, rather than replaces, an explicit array declaration on the
same model: `$table`/`$fillable` only apply from attributes if the
property was left at its empty default, `$hidden` merges (both sources can
hide different fields), and `$casts` merges with the explicit array
winning on a key collision. Mixing styles (some columns as `#[Column]`,
others still in `$fillable`) works fine.

### CRUD

```php
Post::create(['title' => 'Hello', 'body' => '...']);
Post::find($id);
Post::findOrFail($id);          // 404 HttpException if missing
Post::findMany([1, 2, 3]);
Post::first();
Post::firstOrFail();
Post::firstOrCreate(['slug' => 'hello'], ['title' => 'Hello']);
Post::updateOrCreate(['slug' => 'hello'], ['title' => 'Updated']);
Post::all();
Post::upsert($rows, uniqueBy: ['slug']);

$post = new Post(['title' => 'Draft']);
$post->save();          // INSERT (fires creating/created events)
$post->title = 'Live';
$post->save();          // UPDATE — only dirty columns (fires updating/updated)
$post->delete();        // fires deleting/deleted
$post->refresh();
$clone = $post->replicate();
```

`firstOrCreate()`/`updateOrCreate()` retry once on a
`UniqueConstraintViolationException` (re-reading the row a concurrent
request just inserted) — this only closes the race if the matched columns
carry a real unique constraint at the database level; add one via a
migration (`$t->unique('slug')`) if you rely on this.

### Casts

`int`/`integer`, `float`/`double`, `bool`/`boolean`, `string`,
`array`/`json`, `datetime` (→ `DateTimeImmutable`), `encrypted*` (AES-256-GCM
via `Support\Crypto`, requires `APP_KEY`), a `BackedEnum` class name, or any
class implementing `Database\CastsAttributes`.

### Dirty tracking

```php
$post->isDirty();          // any unsaved change
$post->isDirty('title');   // one column
$post->getChanges();       // array of new values not yet persisted... (post-save: values just written)
$post->getOriginal();      // as loaded from the DB
```

### Query scopes

```php
Post::query()->where('published', true)->get();
Post::with('author', 'comments')->get();                 // eager load
Post::with(['author.profile'])->get();                   // nested (dot notation)

// Global scopes
Post::addGlobalScope('published', fn (ModelQueryBuilder $q) => $q->where('published', true));
Post::query(withoutGlobalScopes: ['published'])->get();   // bypass one scope
```

`SoftDeletes` (`Database\Concerns\SoftDeletes`) is a trait applying a global
scope that excludes soft-deleted rows automatically — add the trait plus a
`deleted_at` column (`$t->softDeletes()` in a migration) to opt in.

### Relations

```php
class Post extends Model
{
    public function author(): BelongsTo   { return $this->belongsTo(User::class); }
    public function comments(): HasMany   { return $this->hasMany(Comment::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class); }
}
```

`hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough` — foreign
keys follow Laravel-style conventions (`{table}_id`) and are all overridable
via extra constructor arguments.

### Model events

`creating`/`created`/`updating`/`updated`/`deleting`/`deleted` dispatch
through the `Events\Dispatcher` (`until()` semantics — returning `false`
from a `creating`/`updating`/`deleting` listener cancels the operation) —
**only if** a matching class exists at `Marrow\Events\Model\{Ucfirst}`
(e.g. `Marrow\Events\Model\Creating`). The framework doesn't ship these
classes; define them yourself if you need to hook model lifecycle events —
otherwise `fireEvent()` is a silent no-op and every save/delete just
proceeds.

### Serialization

```php
$post->toArray();   // respects $hidden/$visible, includes $appends and loaded relations
$post->toJson();
```

## Collections & pagination

`QueryBuilder::get()`/`ModelQueryBuilder::get()` both return
`Marrow\Support\Collection` — an array wrapper with `map`/`filter`/`pluck`/
`first`/`isEmpty`/`isNotEmpty`/`count`/`toArray`, etc.

`paginate($perPage, $page)` (on either builder) returns a
`Support\Paginator` (`items`, `total`, `perPage`, `currentPage`, plus derived
last/from/to). For a JSON API, prefer `ApiController::paginate()` — see
[Requests & Responses](http.md) — which builds the conventional
`{data, meta, links}` envelope directly from a raw items array + total.

## Factories & fakes

```php
class PostFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => $this->fake()->sentence(), 'body' => $this->fake()->paragraphs(3, true)];
    }
}

(new PostFactory())->for(Post::class)->count(10)->create();   // persists 10 rows
(new PostFactory())->count(10)->make();                       // Collection of raw attribute arrays, not saved
(new PostFactory())->state(['published' => true])->create();  // override specific fields
```

`for()` only matters for `create()` (it needs a model class to call
`::create()` on) — without it, `create()` guesses the model class from the
factory's own class name (`Database\Factories\PostFactory` →
`Database\Models\Post`; adjust the guess by calling `for()` explicitly if
your factory doesn't live in a sibling `Factories`/`Models` namespace pair).
`make()` just returns the raw attribute arrays and never touches the
database.

Backed by `fakerphp/faker` via `Database\FakeGenerator`. See
[Migrations & Schema](migrations.md#seeders--factories) for wiring factories
into seeders.

## Custom casts

```php
class MoneyCast implements \Marrow\Database\CastsAttributes
{
    public function get(Model $model, string $key, mixed $value): mixed { return $value / 100; }
    public function set(Model $model, string $key, mixed $value): mixed { return (int) round($value * 100); }
}
```

```php
protected array $casts = ['price' => MoneyCast::class];
```
