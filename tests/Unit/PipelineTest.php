<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Http\Request;
use Marrow\Middleware\Pipeline;
use Symfony\Component\HttpFoundation\Response;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Exercises Pipeline's Django-inspired processException hook: a hook-style
// middleware may recover from an exception raised further down the chain
// (an inner middleware or the destination) by implementing
// processException(Request, Throwable, ...$params): ?Response.

beforeEach(function () {
    $this->pipeline = new Pipeline(new Container());
});

test('processException recovers and the response still passes through processResponse', function () {
    $middleware = new class {
        public array $log = [];

        public function processResponse(Request $request, Response $response, mixed ...$params): Response
        {
            $this->log[] = 'processResponse';
            $response->headers->set('X-Recovered', 'true');
            return $response;
        }

        public function processException(Request $request, \Throwable $e, mixed ...$params): ?Response
        {
            $this->log[] = 'processException';
            return new Response('recovered: ' . $e->getMessage());
        }
    };

    $response = $this->pipeline
        ->send(Request::create('/test'))
        ->through([$middleware])
        ->then(fn (Request $req) => throw new \RuntimeException('boom'));

    expect($response->getContent())->toBe('recovered: boom');
    expect($response->headers->get('X-Recovered'))->toBe('true');
    expect($middleware->log)->toBe(['processException', 'processResponse']);
});

test('a middleware without processException lets the exception propagate unchanged', function () {
    $middleware = new class {
        public function processRequest(Request $request, mixed ...$params): ?Response
        {
            return null;
        }
    };

    $run = fn () => $this->pipeline
        ->send(Request::create('/test'))
        ->through([$middleware])
        ->then(fn (Request $req) => throw new \RuntimeException('boom'));

    expect($run)->toThrow(\RuntimeException::class, 'boom');
});

test('processException returning null lets the original exception propagate', function () {
    $middleware = new class {
        public function processException(Request $request, \Throwable $e, mixed ...$params): ?Response
        {
            return null;
        }
    };

    $run = fn () => $this->pipeline
        ->send(Request::create('/test'))
        ->through([$middleware])
        ->then(fn (Request $req) => throw new \RuntimeException('boom'));

    expect($run)->toThrow(\RuntimeException::class, 'boom');
});

test('an outer middleware can recover from an exception raised by an inner one', function () {
    $outer = new class {
        public function processException(Request $request, \Throwable $e, mixed ...$params): ?Response
        {
            return new Response('outer recovered');
        }
    };

    $inner = new class {
        public function processRequest(Request $request, mixed ...$params): ?Response
        {
            return null;
        }
    };

    $response = $this->pipeline
        ->send(Request::create('/test'))
        ->through([$outer, $inner])
        ->then(fn (Request $req) => throw new \RuntimeException('inner boom'));

    expect($response->getContent())->toBe('outer recovered');
});
