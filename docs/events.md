# Events

`Marrow\Events\Dispatcher` is a simple, decoupled pub/sub — no queued
"event bus", just synchronous listener calls.

## Defining an event

Events are plain classes — nothing to extend or implement:

```php
namespace Modules\Blog\Events;

final class PostPublished
{
    public function __construct(public readonly \Modules\Blog\Models\Post $post) {}
}
```

## Listening

```php
$dispatcher = app(\Marrow\Events\Dispatcher::class);

$dispatcher->listen(PostPublished::class, NotifySubscribers::class);   // FQCN, resolved via container
$dispatcher->listen(PostPublished::class, function (PostPublished $e) {
    logger("Published: {$e->post->title}");
});
```

A string listener is resolved through the `Container` and must expose a
`handle($event)` method:

```php
class NotifySubscribers
{
    public function __construct(private readonly \Marrow\Mail\Mailer $mailer) {}

    public function handle(PostPublished $event): void
    {
        // ...
    }
}
```

## Dispatching

```php
$dispatcher->dispatch(new PostPublished($post));

// Global helper:
event(new PostPublished($post));
```

A listener returning `false` stops propagation to any listener registered
after it for that event.

### `until()` — first non-null result

```php
$result = $dispatcher->until(new SomeEvent());
```

Calls each listener in order and returns the **first non-null** result,
short-circuiting the rest. This is how `Model::fireEvent()` implements
cancellable model events internally (`creating`/`updating`/`deleting`
listeners returning `false` cancels the operation) — see
[Database & ORM](database.md#model-events).

## Wiring listeners declaratively from a module

```php
#[Module(
    name: 'blog',
    listeners: [
        \Modules\Blog\Events\PostPublished::class => [
            \Modules\Blog\Listeners\NotifySubscribers::class,
            \Modules\Blog\Listeners\PingSearchIndex::class,
        ],
    ],
)]
class BlogModule extends BaseModule {}
```

`ModuleManager::bootModule()` wires every declared `[EventClass =>
[ListenerClass, ...]]` pair onto the shared `Dispatcher` automatically
during boot — no manual `listen()` calls needed for module-level listeners.
See [Modules (HMVC)](modules.md#lifecycle).

## Introspection

```php
$dispatcher->hasListeners(PostPublished::class);
$dispatcher->forget(PostPublished::class);   // remove every listener for an event
```

## Generating classes

```bash
php forge make:event PostPublished --module=Blog
php forge make:listener NotifySubscribers --event=PostPublished --module=Blog
```
