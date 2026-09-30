<?php

declare(strict_types=1);

namespace Marrow\Tests;

use Marrow\Http\Request as MarrowRequest;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base TestCase for the Marrow framework test suite.
 *
 * Provides HTTP simulation helpers for integration-style tests. Unit tests
 * typically ignore these and test classes/services in isolation.
 *
 * To dispatch through the full HTTP stack, boot a minimal Application before
 * calling get()/post()/etc., then bind the Http Kernel in the container.
 */
abstract class TestCase extends PhpUnitTestCase
{
    // Properties populated via $this->x = ... in Pest beforeEach() closures.
    public mixed $container;
    public mixed $dispatcher;
    public mixed $router;
    public mixed $conn;

    // ── HTTP helpers ─────────────────────────────────────────────────

    public function get(string $uri, array $headers = []): Response
    {
        return $this->dispatch(MarrowRequest::create($uri, 'GET'), $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(MarrowRequest::create($uri, 'POST', $data), $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(MarrowRequest::create($uri, 'PUT', $data), $headers);
    }

    public function patch(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->dispatch(MarrowRequest::create($uri, 'PATCH', $data), $headers);
    }

    public function delete(string $uri, array $headers = []): Response
    {
        return $this->dispatch(MarrowRequest::create($uri, 'DELETE'), $headers);
    }

    // ── Response assertions ──────────────────────────────────────────

    public function assertStatus(Response $response, int $code): void
    {
        $this->assertSame($code, $response->getStatusCode(), "Expected HTTP {$code}, got {$response->getStatusCode()}.");
    }

    public function assertOk(Response $response): void
    {
        $this->assertStatus($response, 200);
    }

    public function assertRedirect(Response $response, ?string $to = null): void
    {
        $this->assertTrue(
            in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Response is not a redirect.'
        );
        if ($to !== null) {
            $this->assertSame($to, $response->headers->get('Location'));
        }
    }

    // ── Dispatcher ───────────────────────────────────────────────────

    protected function dispatch(MarrowRequest $request, array $headers = []): Response
    {
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $app    = \Marrow\Application::getInstance();
        $kernel = $app->getContainer()->make(\Marrow\Http\Kernel::class);
        return $kernel->handle($request);
    }
}
