# The `forge` CLI

`forge` is Marrow's console entry point — the equivalent of Laravel's
`artisan` or AdonisJS's `ace`, built on `symfony/console`.

```bash
php forge list                    # every available command
php forge {command} --help        # a command's own arguments/options
```

`Console\Kernel` registers ~34 framework commands at construction, then
pulls in every command any enabled module declared via
`#[Module(commands: [...])]` once the app actually boots (see
[Modules](modules.md)) — so `php forge list` only shows module commands
after `config/modules.php` correctly enables that module.

## Writing your own command

```php
namespace App\Console\Commands;

use Marrow\Console\Command;

class PruneOldLogs extends Command
{
    protected string $signature = 'logs:prune {--days=30 : Delete logs older than this many days}';
    protected string $description = 'Delete old log files';

    protected function handle(): int
    {
        $days = (int) $this->option('days');
        // ...
        $this->success("Pruned logs older than {$days} days.");
        return self::SUCCESS;
    }
}
```

```bash
php forge make:command PruneOldLogs --module=Blog
```

### Signature syntax

`{arg}` (required), `{arg?}` (optional), `{arg=default}` (optional with a
default), `{--flag}` (boolean option), `{--opt=}` (option taking a value),
`{--opt=default}`. A trailing `: description` on either form documents it
in `--help`.

### Output helpers

`info()`, `success()`, `warn()`, `error()`, `line()`, `newLine($n)`,
`table($headers, $rows)`, `twoColumnDetail($left, $right, $style)`,
`migrationLine($name, $ms, $rollback)`, `task($title, callable)` (spinner →
DONE/FAIL), `progress($max, callable)` (progress bar).

### Input helpers

`argument($name)`, `option($name)`, `argumentOrAsk($name, $question, $default)`,
`ask($question, $default)`, `confirm($question, $default)`,
`secret($question)` (hidden input), `choice($question, $choices, $default)`.

### Calling another command

```php
$this->call('db:seed', ['--class' => 'Database\\Seeders\\RolesTableSeeder']);
```

## The `--module` convention

Every `make:*` generator accepts `--module=Name`. With it, the class is
generated under `modules/Name/...`, namespaced `Modules\Name\...`; without
it, under `app/...`, namespaced `App\...`. `moduleOption()` normalizes the
value to PascalCase, matching `make:module`'s own directory naming.

For `make:controller` specifically, always pass `--module`: routing only
ever loads `modules/*/routes.php` (see [Routing](routing.md) and
[Modules](modules.md)), so a controller generated into `app/Controllers`
(the default when `--module` is omitted) has no route loader that will
ever reach it.

### Default output paths

Every path below is created the moment you run the command
(`@mkdir(..., 0755, true)` before writing) — none of them need to
pre-exist, which is why the skeleton doesn't ship empty versions of any of
them. This is the full picture behind the generic "`app/...`, namespaced
`App\...`" rule above:

| Command | Default path (no `--module`) | Namespace |
|---|---|---|
| `make:controller` | `app/Controllers/{Name}.php` | `App\Controllers` |
| `make:model` | `app/Models/{Name}.php` | `App\Models` |
| `make:policy` | `app/Policies/{Name}.php` | `App\Policies` |
| `make:middleware` | `app/Middleware/{Name}.php` | `App\Middleware` — **no `--module` option exists at all**; middleware always lands here regardless |
| `make:command` | `app/Commands/{Name}.php` | `App\Commands` |
| `make:event` | `app/Events/{Name}.php` | `App\Events` |
| `make:listener` | `app/Listeners/{Name}.php` | `App\Listeners` |
| `make:service` | `app/Services/{Name}.php` | `App\Services` |
| `make:job` | `app/Jobs/{Name}.php` | `App\Jobs` |
| `make:mail` | `app/Mail/{Name}.php` | `App\Mail` |
| `make:notification` | `app/Notifications/{Name}.php` | `App\Notifications` |
| `make:form-request` | `app/Http/Requests/{Name}.php` | `App\Http\Requests` |
| `make:resource` | `app/Http/Resources/{Name}.php` | `App\Http\Resources` |
| `make:component` | `app/View/Components/{Name}.php` | `App\View\Components` |
| `make:migration` | `database/migrations/{timestamp}_{name}.php` | *(none — global scope)* |
| `make:seeder` | `database/seeders/{Name}.php` | `Database\Seeders` |
| `make:factory` | `database/factories/{Name}.php` | `Database\Factories` |
| `make:test` | `tests/Feature/{Name}.php` (`tests/Unit/` with `--unit`) | *(none — Pest doesn't need one)* |

The skeleton's `composer.json` maps the single PSR-4 prefix `App\` →
`app/`, so every nested namespace above (`App\Http\Requests`,
`App\View\Components`, ...) resolves correctly with no extra autoload
entries needed.

A command that generates something meant to act as a container-resolvable
provider (`make:policy`, `make:controller`, ...) also inserts the generated
FQCN into the target module's `providers: [...]` array automatically
(`registerAsProvider()`) — so a freshly generated class is immediately
resolvable and protected by module isolation, with no manual edit to the
module's `#[Module(...)]` attribute.

## Command reference

### Scaffolding (`make:*`)

| Command | Notes |
|---|---|
| `make:module {name?}` | full HMVC module skeleton |
| `make:controller {name?} {--module=} {--resource} {--api}` | `--resource` adds the 7 conventional methods; `--api` extends `ApiController` instead of `Controller` |
| `make:model {name?} {--module=} {--migration} {--factory}` | |
| `make:migration {name?} {--module=}` | timestamped file — see [Migrations](migrations.md) |
| `make:seeder {name?} {--module=}` | |
| `make:factory {name?} {--module=}` | |
| `make:middleware {name?}` | |
| `make:command {name?} {--module=}` | |
| `make:event {name?} {--module=}` | |
| `make:listener {name?} {--event=} {--module=}` | |
| `make:service {name?} {--module=}` | |
| `make:policy {name} {--model=} {--module=}` | extends `Auth\Policy` — see [Authentication & RBAC](authentication.md) |
| `make:job {name?} {--module=}` | |
| `make:mail {name?} {--module=}` | |
| `make:notification {name?} {--module=}` | |
| `make:form-request {name?} {--module=}` | |
| `make:resource {name?} {--collection} {--module=}` | JSON API resource |
| `make:component {name?} {--module=}` | Twig view component |
| `make:test {name?} {--unit} {--module=}` | Pest test |

### Database

| Command | Notes |
|---|---|
| `migrate {--path=} {--fresh} {--seed} {--rollback} {--force}` | `--fresh` drops every table first — confirms interactively unless `--force` |
| `migrate:fresh {--seed} {--force}` | drops everything and re-runs all migrations; asks for confirmation unless `--force` — needed for CI/scripts |
| `migrate:rollback {--path=}` | rolls back the last batch |
| `migrate:status` | ran vs. pending, per discovered path |
| `db:seed {--class=Database\Seeders\DatabaseSeeder}` | |

### Introspection & tooling

| Command | Notes |
|---|---|
| `route:list` | every registered route |
| `module:graph {--check}` | dependency graph in boot order |
| `about` | app name/version/environment summary |
| `tinker` | REPL via `psy/psysh`, booted with the full container available |
| `twig:lint {path}` | syntax-check Twig templates |
| `cache:clear` | flushes the configured cache driver |
| `key:generate {--show}` | writes `APP_KEY` to `.env` (`--show` prints without writing); errors if `.env` doesn't exist yet |
| `down {--secret=}` / `up` | maintenance mode — see [Security Hardening](security.md#maintenance-mode) |
| `serve {--host=localhost} {--port=8080} {--watch-css}` | PHP built-in server with colourised request logging, crash auto-restart, and free-port fallback; `--watch-css` also spawns `npm run dev` if `package.json` exists |
| `queue:work {--queue=default} {--sleep=3} {--once}` | see [Queues & Jobs](queue.md) |
| `schedule:run` | see [Task Scheduling](scheduler.md) |

`serve` looks for `bin/server.php` and passes it to PHP's built-in server as
a router script if present (the skeleton ships one) — falls back to
serving `public/` directly with no router otherwise.

## Extending `forge` from a package

A module distributed as a Composer package can register its own `forge`
commands the same way a local module does, via `#[Module(commands: [...])]`
(see [Modules](modules.md#distributing-a-module-as-a-package)).
`marrow/compass` does exactly this: installing it adds
`ai:context`, which generates an `AGENTS.md` file listing every registered
route, module, and config file — a live map for an AI coding agent (or a new
contributor) to read before exploring the codebase, immediately available
after `composer require --dev marrow/compass`, no wiring step.
