<?php

declare(strict_types=1);

namespace Marrow\Http\Resources;

use Marrow\Http\Request;
use Marrow\Http\Response;

/**
 * Wraps an iterable of models/arrays and transforms each via a JsonResource class.
 *
 * Usage (auto-created via JsonResource::collection()):
 *   UserResource::collection($users)
 *
 * Response shape: { "data": [...], "meta": {...} }
 */
final class ResourceCollection
{
    private array $with = [];
    private array $meta = [];
    private int $status = 200;

    public function __construct(
        private readonly iterable $resources,
        private readonly string $resourceClass
    ) {
    }

    public function toArray(Request $request): array
    {
        $data = [];
        foreach ($this->resources as $item) {
            $resource = new $this->resourceClass($item);
            $data[]   = $resource->toArray($request);
        }
        return $data;
    }

    public function toResponse(Request $request): Response
    {
        $data = [];
        foreach ($this->resources as $item) {
            $resource = new $this->resourceClass($item);
            $data[] = $resource->toArray($request);
        }

        $payload = ['data' => $data];

        if (!empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }

        if (!empty($this->with)) {
            $payload = array_merge($payload, $this->with);
        }

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $this->status,
            ['Content-Type' => 'application/json']
        );
    }

    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }

    public function additional(array $data): static
    {
        $this->with = array_merge($this->with, $data);
        return $this;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function count(): int
    {
        return is_countable($this->resources) ? count($this->resources) : iterator_count(
            (function () {
                yield from $this->resources; })()
        );
    }
}
