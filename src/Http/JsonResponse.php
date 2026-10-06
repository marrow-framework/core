<?php

declare(strict_types=1);

namespace Marrow\Http;

use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;

/**
 * JSON response — a genuine subclass of `Response` (see its docblock),
 * composing a `Symfony\Component\HttpFoundation\JsonResponse` internally
 * instead of `Response`'s plain base one, so `setData()`/`getData()`/
 * `setCallback()`/... all still work via the inherited `__call()`
 * forwarding, with no need to redeclare them here.
 *
 * @mixin SymfonyJsonResponse
 */
class JsonResponse extends Response
{
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        $this->response = new SymfonyJsonResponse($data, $status, $headers);
        $this->headers = $this->response->headers;
        $this->headers->set('Content-Type', 'application/json');
    }
}
