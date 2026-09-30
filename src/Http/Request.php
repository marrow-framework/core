<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Validation\ValidatorFactory;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Extends Symfony Request with framework helpers: wantsJson, bearerToken,
 * route parameters, validated input, validate() shortcut, and file uploads.
 */
class Request extends SymfonyRequest
{
    private array $routeParams    = [];
    private ?array $validatedData = null;

    public static function createFromGlobals(): static
    {
        $request = parent::createFromGlobals();
        // Re-wrap uploaded files in our UploadedFile class
        $request->wrapUploadedFiles();
        // @phpstan-ignore-next-line
        return $request;
    }

    // ── Route parameters ────────────────────────────────────────────

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function allRouteParams(): array
    {
        return $this->routeParams;
    }

    // ── Content negotiation ──────────────────────────────────────────

    public function wantsJson(): bool
    {
        $accept = $this->headers->get('Accept', '');
        return str_contains((string) $accept, 'application/json')
            || str_contains((string) $accept, 'application/javascript')
            || $this->isXmlHttpRequest();
    }

    public function isJson(): bool
    {
        return str_contains($this->headers->get('Content-Type', ''), 'application/json');
    }

    // ── Auth helpers ─────────────────────────────────────────────────

    public function bearerToken(): ?string
    {
        $auth = $this->headers->get('Authorization', '');
        if (str_starts_with((string) $auth, 'Bearer ')) {
            return substr((string) $auth, 7);
        }
        return null;
    }

    // ── Input helpers ────────────────────────────────────────────────

    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->request->has($key)) {
            return $this->request->get($key);
        }
        if ($this->query->has($key)) {
            return $this->query->get($key);
        }
        return $default;
    }

    public function all(): array
    {
        return array_merge($this->query->all(), $this->request->all());
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return $this->request->has($key) || $this->query->has($key);
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        $data = json_decode((string) $this->getContent(), true) ?? [];
        if ($key === null) {
            return $data;
        }
        return $data[$key] ?? $default;
    }

    // ── File helpers ─────────────────────────────────────────────────

    /**
     * Returns a single uploaded file by field name, or null if not present / invalid.
     */
    public function file(string $key): ?UploadedFile
    {
        $file = $this->files->get($key);
        if ($file instanceof UploadedFile) {
            return $file->isValid() ? $file : null;
        }
        return null;
    }

    /**
     * Returns an array of uploaded files for a multi-file field.
     *
     * @return UploadedFile[]
     */
    public function fileList(string $key): array
    {
        $files = $this->files->get($key);
        if (is_array($files)) {
            return array_values(array_filter(
                $files,
                fn($f) => $f instanceof UploadedFile && $f->isValid()
            ));
        }
        if ($files instanceof UploadedFile && $files->isValid()) {
            return [$files];
        }
        return [];
    }

    /**
     * True if the named file was uploaded and is valid.
     */
    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    /**
     * All uploaded files as a flat name→UploadedFile map.
     *
     * @return array<string, UploadedFile>
     */
    public function allFiles(): array
    {
        $result = [];
        foreach ($this->files->all() as $key => $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $result[$key] = $file;
            }
        }
        return $result;
    }

    // ── Validation ───────────────────────────────────────────────────

    /**
     * Validate request data (including uploaded files).
     *
     * @throws \Marrow\Validation\ValidationException If validation fails.
     */
    public function validate(array $rules, array $messages = []): array
    {
        $data    = array_merge($this->all(), $this->allFiles());
        $factory = new ValidatorFactory();
        $validator = $factory->make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new \Marrow\Validation\ValidationException($validator);
        }

        $this->validatedData = $validator->validated();
        return $this->validatedData;
    }

    public function validated(): mixed
    {
        return $this->validatedData ?? [];
    }

    // ── Method spoofing ──────────────────────────────────────────────

    public function getMethod(): string
    {
        if (parent::getMethod() === 'POST') {
            $spoofed = strtoupper((string) ($this->request->get('_method', '')));
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }
        return parent::getMethod();
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Replace Symfony UploadedFile instances with our extended UploadedFile class
     * so that store() / hashName() etc. are available everywhere.
     */
    protected function wrapUploadedFiles(): void
    {
        $wrapped = $this->rewrapBag($this->files->all());
        $this->files = new FileBag($wrapped);
    }

    private function rewrapBag(array $files): array
    {
        $out = [];
        foreach ($files as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->rewrapBag($value);
            } elseif ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                && !$value instanceof UploadedFile) {
                $out[$key] = new UploadedFile(
                    $value->getPathname(),
                    $value->getClientOriginalName(),
                    $value->getClientMimeType(),
                    $value->getError(),
                    true // test mode — file already moved to temp
                );
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
