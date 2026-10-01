<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Exceptions\HttpException;
use Marrow\Exceptions\Handler;
use Marrow\Http\Request;
use Marrow\Template\Engine;
use Psr\Log\NullLogger;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Regression coverage for a real bug: HttpException::withHeaders() (used by
// ThrottleRequests' Retry-After/X-RateLimit-* on a 429, and by SessionGuard's
// login-lockout Retry-After) had no effect at all — render() built a brand
// new Response/JsonResponse in every branch (JSON / debug / production error
// page) and none of them ever read $e->getHeaders(), so the headers were
// silently discarded between being set and the response actually being sent.

function makeHandler(bool $debug = false): Handler
{
    $container = new Container();

    // FrameworkExtension (added by Engine below) eagerly resolves
    // Application::class in its own constructor. The real class's
    // constructor boots an entire app (own Container, env, core service
    // bindings) and — worse — unconditionally overwrites the global
    // Application::getInstance() singleton, which would leak into every
    // other test in this process (see ValidatorFactoryTest, which relies on
    // no Application ever having been booted). newInstanceWithoutConstructor()
    // sidesteps both: a real, correctly-typed Application the extension can
    // hold a reference to, with none of its side effects — safe here since
    // none of the templates under test call a Twig function that actually
    // touches it (auth_user(), config(), ...).
    $container->instance(
        \Marrow\Application::class,
        (new \ReflectionClass(\Marrow\Application::class))->newInstanceWithoutConstructor()
    );

    $cachePath = sys_get_temp_dir() . '/marrow-test-twig-cache-' . bin2hex(random_bytes(4));
    $engine = new Engine($container, sys_get_temp_dir() . '/marrow-test-views-missing', $cachePath, $debug);

    return new Handler(new NullLogger(), $engine, $debug, sys_get_temp_dir() . '/marrow-test-error-views-missing');
}

test('headers set via HttpException::withHeaders() reach the final JSON response', function () {
    $handler = makeHandler();
    $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    $exception = (new HttpException(429, 'Too Many Requests.'))->withHeaders(['Retry-After' => '42']);

    $response = $handler->render($request, $exception);

    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->get('Retry-After'))->toBe('42');
});

test('headers set via HttpException::withHeaders() reach the final production error-page response', function () {
    $handler = makeHandler(debug: false);
    $request = Request::create('/login', 'POST');
    $exception = (new HttpException(429, 'Too Many Requests.'))->withHeaders(['Retry-After' => '17']);

    $response = $handler->render($request, $exception);

    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->get('Retry-After'))->toBe('17');
});

test('headers set via HttpException::withHeaders() reach the final debug-page response', function () {
    $handler = makeHandler(debug: true);
    $request = Request::create('/login', 'POST');
    $exception = (new HttpException(429, 'Too Many Requests.'))->withHeaders(['Retry-After' => '5']);

    $response = $handler->render($request, $exception);

    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->get('Retry-After'))->toBe('5');
});

test('a plain exception with no headers renders normally', function () {
    $handler = makeHandler();
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

    $response = $handler->render($request, new \RuntimeException('boom'));

    expect($response->getStatusCode())->toBe(500);
});
