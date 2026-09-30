<?php

declare(strict_types=1);

namespace Marrow\Http;

use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;

/**
 * JSON response wrapper (thin layer over Symfony JsonResponse).
 */
class JsonResponse extends SymfonyJsonResponse
{
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        parent::__construct($data, $status, $headers);
        $this->headers->set('Content-Type', 'application/json');
    }
}
