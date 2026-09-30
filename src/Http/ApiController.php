<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Http\Resources\JsonResource;
use Marrow\Http\Resources\ResourceCollection;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Base controller for API endpoints.
 *
 * Extends {@see Controller}, so the view/redirect/validate/authorize/can
 * helpers are available too, and adds fluent helpers for consistent JSON
 * response shapes:
 *
 *   return $this->ok($data);              // 200 { "data": ... }
 *   return $this->created($resource);     // 201 { "data": ... }
 *   return $this->noContent();            // 204
 *   return $this->paginate($items, ...);  // 200 { "data": [...], "meta": {...}, "links": {...} }
 *   return $this->notFound();             // 404 { "error": ... }
 *   return $this->unprocessable($errors); // 422 { "message": ..., "errors": {...} }
 *
 * Responses always include an "ok" boolean and the HTTP status code.
 */
abstract class ApiController extends Controller
{
    // ── Success responses ─────────────────────────────────────────────

    protected function ok(mixed $data = null, array $meta = []): JsonResponse
    {
        return $this->success($data, 200, $meta);
    }

    protected function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return $this->success($data, 201, $meta);
    }

    protected function accepted(mixed $data = null): JsonResponse
    {
        return $this->success($data, 202);
    }

    protected function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    // ── Pagination ────────────────────────────────────────────────────

    /**
     * Return a paginated response.
     *
     * @param array<int, mixed> $items Current page items (already sliced)
     * @param int $total Total number of records
     * @param int $perPage Items per page
     * @param int $page Current page (1-based)
     */
    protected function paginate(
        array  $items,
        int    $total,
        int    $perPage = 15,
        int    $page    = 1
    ): JsonResponse {
        $lastPage   = max(1, (int) ceil($total / $perPage));
        $from       = $total > 0 ? ($page - 1) * $perPage + 1 : null;
        $to         = $total > 0 ? min($page * $perPage, $total) : null;

        $serialized = $this->serialize($items);

        $payload = [
            'ok'   => true,
            'data' => $serialized,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
                'from'         => $from,
                'to'           => $to,
            ],
            'links' => [
                'first' => $this->pageUrl(1),
                'last'  => $this->pageUrl($lastPage),
                'prev'  => $page > 1         ? $this->pageUrl($page - 1) : null,
                'next'  => $page < $lastPage  ? $this->pageUrl($page + 1) : null,
            ],
        ];

        $response = new JsonResponse($payload);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Per-Page', (string) $perPage);

        return $response;
    }

    // ── Error responses ───────────────────────────────────────────────

    protected function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return $this->error($message, 404);
    }

    protected function unauthorized(string $message = 'Unauthorized.'): JsonResponse
    {
        return $this->error($message, 401);
    }

    protected function forbidden(string $message = "Cette action n'est pas autorisée."): JsonResponse
    {
        return $this->error($message, 403);
    }

    protected function unprocessable(array $errors = [], string $message = 'The given data was invalid.'): JsonResponse
    {
        return new JsonResponse([
            'ok'      => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    protected function error(string $message, int $status = 500, array $extra = []): JsonResponse
    {
        return new JsonResponse(array_merge([
            'ok'      => false,
            'message' => $message,
        ], $extra), $status);
    }

    // Authorization helpers (authorize / can / cannot) are inherited from Controller.

    // ── Internal ──────────────────────────────────────────────────────

    private function success(mixed $data, int $status, array $meta = []): JsonResponse
    {
        $payload = ['ok' => true, 'data' => $this->serialize($data)];
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        return new JsonResponse($payload, $status);
    }

    private function serialize(mixed $data): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->toArray($this->request);
        }

        if ($data instanceof ResourceCollection) {
            return $data->toArray($this->request);
        }

        if (is_object($data) && method_exists($data, 'toArray')) {
            return $data->toArray();
        }

        if (is_array($data)) {
            return array_map(fn($item) => $this->serialize($item), $data);
        }

        return $data;
    }

    private function pageUrl(int $page): string
    {
        $params = array_merge($this->request->query->all(), ['page' => $page]);
        $qs     = http_build_query($params);
        return $this->request->getSchemeAndHttpHost() . $this->request->getBaseUrl() . $this->request->getPathInfo()
            . ($qs ? '?' . $qs : '');
    }
}
