<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Auth\AuthManager;
use Marrow\Database\Connection;
use Marrow\Exceptions\HttpException;
use Marrow\Http\RedirectResponse;
use Marrow\Http\Request;
use Marrow\Http\Response;
use Marrow\Middleware\Authenticate;
use Marrow\Session\SessionManager;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Regression coverage: Authenticate used to `throw new HttpException(302, '')`
// for an unauthenticated non-JSON request. The Handler has no view for status
// 302 and — before the matching Handler fix — never copied
// HttpException::getHeaders() onto the response it built either, so that
// exception never actually produced a redirect: it just rendered a broken,
// Location-less error page. Authenticate now returns a real RedirectResponse
// directly instead, the same way RedirectIfAuthenticated already does.

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, password TEXT)');
    $this->conn = $conn;
    $this->auth = new AuthManager($conn, new SessionManager(), []);
});

test('an unauthenticated non-JSON request gets a real redirect to the default login path', function () {
    $middleware = new Authenticate($this->auth);
    $request = Request::create('/dashboard', 'GET');

    $response = $middleware->handle($request, fn ($req) => new Response('ok'));

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getStatusCode())->toBe(302);
    expect($response->getTargetUrl())->toBe('/login');
});

test('a custom login redirect path is honoured', function () {
    $middleware = new Authenticate($this->auth, '/connexion');
    $request = Request::create('/dashboard', 'GET');

    $response = $middleware->handle($request, fn ($req) => new Response('ok'));

    expect($response->getTargetUrl())->toBe('/connexion');
});

test('an unauthenticated JSON request still gets a 401 HttpException, not a redirect', function () {
    $middleware = new Authenticate($this->auth);
    $request = Request::create('/dashboard', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

    expect(fn () => $middleware->handle($request, fn ($req) => new Response('ok')))
        ->toThrow(HttpException::class);
});

test('an authenticated request passes through to the next middleware untouched', function () {
    $this->conn->insert('users', ['email' => 'jane@example.com', 'password' => 'hash']);
    $this->auth->login((object) ['id' => 1, 'email' => 'jane@example.com']);

    $middleware = new Authenticate($this->auth);
    $request = Request::create('/dashboard', 'GET');

    $response = $middleware->handle($request, fn ($req) => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});
