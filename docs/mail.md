# Mail

`Marrow\Mail\Mailer` wraps `symfony/mailer`.

## Configuration

```php
// config/mail.php
return [
    'dsn' => env('MAIL_DSN', 'null://null'),
    'from' => ['address' => env('MAIL_FROM_ADDRESS'), 'name' => env('MAIL_FROM_NAME')],
];
```

`dsn` is passed straight to `Transport::fromDsn()` — any DSN Symfony Mailer
supports works: `null://null` (discard everything, safe default for local
dev), `smtp://user:pass@host:port`, `ses+api://KEY:SECRET@default?region=...`,
etc. See the
[Symfony Mailer transport docs](https://symfony.com/doc/current/mailer.html#using-built-in-transports).

`from` is applied by `Mailer::send()` to any message that doesn't already
set its own `From` address.

## Sending directly (fluent)

```php
app(\Marrow\Mail\Mailer::class)
    ->to('jane@example.com')
    ->cc('team@example.com')
    ->subject('Welcome')
    ->view('@blog/emails/welcome', ['user' => $user])   // renders a Twig template as the HTML body
    ->attach(storage_path('app/invoice.pdf'), 'invoice.pdf')
    ->send();
```

`view()` (and `html()`/`text()`) resolve `Template\Engine` ambiently via
`Application::getInstance()` rather than through constructor injection —
the same accepted escape-hatch pattern as `Response::view()`, since
`PendingMail` is built directly by application code (`$mailer->to(...)`),
not resolved through the container.

## `Mailable` — reusable, testable messages

```php
use Marrow\Mail\Mailable;
use Symfony\Component\Mime\Email;

class WelcomeMail extends Mailable
{
    public function __construct(private User $user) {}

    public function build(array $config = []): Email
    {
        return $this->makeEmail()
            ->to($this->user->email)
            ->subject('Welcome to Marrow')
            ->html($this->renderView('@blog/emails/welcome', ['user' => $this->user]));
    }
}
```

```php
app(\Marrow\Mail\Mailer::class)->send(new WelcomeMail($user));
```

`build()` receives the raw `mail` config array (in case a `Mailable` needs
it), and must return a `Symfony\Component\Mime\Email`. `Mailer::send()`
applies the configured `from` address if `build()` didn't set one.

## Testing

Set `MAIL_DSN=null://null` (the default) so tests never attempt a real
network send — every message is accepted and discarded by the `null`
transport.
