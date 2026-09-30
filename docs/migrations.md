# Migrations & Schema

## Writing a migration

```php
use Marrow\Database\Migrations\Migration;
use Marrow\Database\Schema\Schema;
use Marrow\Database\Schema\Table;

class CreatePostsTable extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Table $t) {
            $t->id();
            $t->string('title');
            $t->text('body');
            $t->boolean('published')->default(false);
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index('published');
        });
    }

    public function down(): void
    {
        Schema::drop('posts');
    }
}
```

Generate the scaffold with:

```bash
php forge make:migration create_posts_table
php forge make:migration add_status_to_posts_table --module=Blog
```

### File naming

`{Y_m_d_His}_{name}.php`, e.g. `2024_06_01_120000_create_posts_table.php`.
The class name is derived by stripping the timestamp and PascalCasing the
rest (`create_posts_table` → `CreatePostsTable`) — **the class name must
match the file name** unless you use the anonymous-class form:

```php
return new class extends Migration {
    public function up(): void { /* ... */ }
    public function down(): void { /* ... */ }
};
```

## `Schema` — table definitions

Static entry point: `Schema::create($table, $callback)`, `Schema::table($table, $callback)`
(add/drop columns only — no column alteration), `Schema::drop($table)` /
`dropIfExists()`, `Schema::hasTable()`, `Schema::hasColumn()`.

SQL is generated per-dialect (SQLite / MySQL-MariaDB / PostgreSQL / generic
fallback) without going through Doctrine's own DDL compiler, so dialect
quirks (SQLite's inline `AUTOINCREMENT`, MySQL's `ENGINE=InnoDB`, PostgreSQL's
`BIGSERIAL`, index syntax differences) are handled explicitly.

### `Table` — columns

```php
$t->id('id');                       // BIGINT auto-increment primary key
$t->bigIncrements('id');            // alias for id()
$t->string('name', 255);
$t->text('body');
$t->integer('views');
$t->bigInteger('count');
$t->unsignedBigInteger('user_id');
$t->boolean('active');
$t->float('rating', 8, 2);
$t->decimal('price', 8, 2);
$t->json('meta');
$t->timestamp('published_at');
$t->datetime('starts_at');
$t->date('birthday');
$t->enum('status', ['draft', 'published']);   // stored as VARCHAR(60)
$t->timestamps();                    // created_at + updated_at, nullable
$t->softDeletes();                   // deleted_at, nullable
$t->dropColumn(['old_column']);      // Schema::table() only
```

Column-returning methods yield a `ColumnDefinition`, chainable with:

```php
$t->string('email')->unique()->nullable(false);
$t->integer('attempts')->default(0);
$t->timestamp('processed_at')->useCurrent();   // DEFAULT CURRENT_TIMESTAMP
$t->string('status')->after('email');          // MySQL only, ignored elsewhere
```

### Foreign keys

```php
$t->foreignId('user_id')->constrained()->cascadeOnDelete();
// or explicitly:
$t->foreignId('author_id')->constrained('users', 'id')->nullOnDelete();
```

`constrained()` with no argument guesses the referenced table from the
column name (`user_id` → `users`). **Note:** SQLite ignores inline `FOREIGN
KEY` constraints in the generated `CREATE TABLE` unless
`PRAGMA foreign_keys = ON` is set at the connection level — the constraint
is still declared for MySQL/PostgreSQL.

### Indices

```php
$t->index('slug');
$t->index(['category_id', 'published']);
$t->unique('email');
$t->primary(['tenant_id', 'id']);
```

## `Migrator` — running migrations

Discovery (`Migrator::discoverPaths()`) scans `database/migrations` **and**
every `modules/*/Database/Migrations` (or `modules/*/Migrations`) —
migration files are executed in filename (timestamp) order across all
discovered paths combined.

```bash
php forge migrate                  # run pending migrations
php forge migrate --seed           # ...then run the database seeder
php forge migrate --fresh          # drop everything, then re-run all migrations
php forge migrate --fresh --seed
php forge migrate --path=modules/Blog/Database/Migrations
php forge migrate:fresh --seed --force   # equivalent standalone command — also needs --force to skip the confirmation prompt non-interactively
php forge migrate:rollback         # roll back the last batch
php forge migrate:status           # show ran/pending state
```

Each migration's `up()` and its tracking-row insert run inside one
transaction — a migration that throws partway through is never marked as
ran (avoiding a silently-skipped-but-half-applied migration on retry). This
buys real atomicity on SQLite/PostgreSQL; MySQL DDL auto-commits regardless
of the transaction, so a failed MySQL migration may still leave partial DDL
applied even though the tracking row correctly reflects "not ran".

`migrate --fresh` calls `dropAll()` **per discovered path**, which can be
surprising with multiple module migration directories — if you hit
inconsistent results with `--fresh` across several modules, delete the
SQLite file (or drop the database) and run a plain `migrate` instead.

## Seeders

```php
namespace Database\Seeders;

use Marrow\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesTableSeeder::class);
    }
}
```

```bash
php forge db:seed                                   # runs Database\Seeders\DatabaseSeeder
php forge db:seed --class=Database\\Seeders\\RolesTableSeeder
```

The base `Seeder` class has **no** `output()`/`info()` helper — use plain
`echo` from inside `run()` if you want console feedback.

## Factories

See [Database & ORM](database.md#factories--fakes) for the `Factory` API —
call a factory's `create()` from a seeder to populate a table with fake
data:

```php
class PostsTableSeeder extends Seeder
{
    public function run(): void
    {
        (new \Database\Factories\PostFactory())->count(20)->create();
    }
}
```
