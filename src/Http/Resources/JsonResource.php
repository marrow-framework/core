<?php

declare(strict_types=1);

namespace Marrow\Http\Resources;

use Marrow\Http\Request;
use Marrow\Http\Response;
use JsonSerializable;

/**
 * API Resource transformer — maps a model/array to a JSON-safe structure.
 *
 * Usage:
 *   class UserResource extends JsonResource {
 *       public function toArray(Request $request): array {
 *           return ['id' => $this->resource->id, 'name' => $this->resource->name];
 *       }
 *   }
 *
 *   // In controller:
 *   return new UserResource($user);
 *   return UserResource::collection($users);
 */
abstract class JsonResource implements JsonSerializable
{
    /** @var mixed The underlying data (model, array, stdClass, …) */
    protected mixed $resource;

    /** Additional metadata merged into the response envelope. */
    protected array $with = [];

    /** HTTP status code for the response. */
    protected int $status = 200;

    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    // ── Contract ──────────────────────────────────────────────────────

    abstract public function toArray(Request $request): array;

    // ── Collection factory ────────────────────────────────────────────

    public static function collection(iterable $resources): ResourceCollection
    {
        return new ResourceCollection($resources, static::class);
    }

    // ── Response ──────────────────────────────────────────────────────

    public function toResponse(Request $request): Response
    {
        $payload = $this->toArray($request);
        if (!empty($this->with)) {
            $payload = array_merge($payload, $this->with);
        }
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $this->status,
            ['Content-Type' => 'application/json']
        );
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function additional(array $data): static
    {
        $this->with = array_merge($this->with, $data);
        return $this;
    }

    // ── Magic property access ─────────────────────────────────────────

    public function __get(string $name): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$name] ?? null;
        }
        return $this->resource->$name ?? null;
    }

    public function __isset(string $name): bool
    {
        if (is_array($this->resource)) {
            return isset($this->resource[$name]);
        }
        return isset($this->resource->$name);
    }

    public function jsonSerialize(): array
    {
        // Used when the resource itself is JSON-encoded (e.g. nested)
        return $this->toArray(Request::createFromGlobals());
    }
}
