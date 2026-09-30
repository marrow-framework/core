<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Cache\CacheManager;
use Marrow\Container;
use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
use Marrow\Middleware\Pipeline;
use Marrow\Middleware\ThrottleRequests;
use Marrow\RateLimiting\RateLimiter;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Regression coverage for a real bug: 'throttle:N,M' middleware syntax always
// passes its parameters as strings (Pipeline::resolve() splits the raw
// "N,M" string on ','), but ThrottleRequests::handle() used to type-hint
// $maxAttempts/$decayMinutes as plain `int`. Under declare(strict_types=1)
// that rejects a string outright — every request to a route using
// 'throttle:N,M' threw a TypeError, uncaught by any prior test because they
// all constructed ThrottleRequests directly with real ints instead of going
// through the pipeline's string-splitting path.

function throttleMiddleware(): ThrottleRequests
{
    $cache = new CacheManager(sys_get_temp_dir(), 'array');
    return new ThrottleRequests(new RateLimiter($cache));
}

test('handle() accepts string parameters exactly as the pipeline colon-syntax provides them', function () {
    $middleware = throttleMiddleware();
    $request = Request::create('/login', 'POST');

    // Matches what Pipeline::resolve() actually passes for 'throttle:3,1' —
    // both args as strings, not ints. This alone reproduces the bug.
    $response = $middleware->handle($request, fn ($req) => new \Marrow\Http\Response('ok'), '3', '1');

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('3');
});

test('a route using throttle:N,M survives a full Pipeline dispatch without a TypeError', function () {
    $container = new Container();
    $container->instance(RateLimiter::class, new RateLimiter(new CacheManager(sys_get_temp_dir(), 'array')));

    $pipeline = new Pipeline($container);
    $request = Request::create('/login', 'POST');

    $response = $pipeline
        ->send($request)
        ->through(['Marrow\\Middleware\\ThrottleRequests:3,1'])
        ->then(fn ($req) => new \Marrow\Http\Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('the third request within the window is rejected with 429 once the limit is reached', function () {
    $middleware = throttleMiddleware();
    $next = fn ($req) => new \Marrow\Http\Response('ok');

    $middleware->handle(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']), $next, '2', '1');
    $middleware->handle(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']), $next, '2', '1');

    expect(fn () => $middleware->handle(
        Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']),
        $next,
        '2',
        '1'
    ))->toThrow(HttpException::class);
});
