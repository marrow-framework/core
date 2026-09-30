<?php

declare(strict_types=1);

namespace Marrow\Http;

use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * Uploaded file with framework-level storage helpers.
 *
 * Wraps Symfony's UploadedFile and adds:
 *   store()    — move to storage/app/{directory} with a hash name
 *   storeAs()  — move to storage/app/{directory} with a given name
 *   hashName() — generate a unique hash-based filename
 *
 * Validation rules (in ValidatorInstance):
 *   file            — value is a valid uploaded file
 *   image           — common image extensions
 *   mimes:jpg,png   — allowed client-declared extensions
 *   mime_types:...  — allowed actual MIME types
 *   max:2048        — max size in KB when value is a file
 *   min:1           — min size in KB when value is a file
 *   dimensions:...  — image dimension constraints
 */
class UploadedFile extends SymfonyUploadedFile
{
    // ── Storage helpers ──────────────────────────────────────────────

    /**
     * Store the file under storage/app/{directory} using a hash-based name.
     * Returns the relative path "directory/hash.ext" on success, or false.
     */
    public function store(string $directory = 'uploads'): string|false
    {
        $name = $this->hashName();
        return $this->storeAs($directory, $name);
    }

    /**
     * Store the file under storage/app/{directory}/{name}.
     * Returns the relative path "directory/name" on success, or false.
     */
    public function storeAs(string $directory, string $name): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $root = $this->storageRoot();
        $dest = rtrim($root . '/' . ltrim($directory, '/'), '/');

        if (!is_dir($dest)) {
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                return false;
            }
        }

        try {
            $this->move($dest, $name);
            return ltrim($directory, '/') . '/' . $name;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate a unique hash-based filename, preserving the client extension.
     */
    public function hashName(): string
    {
        $hash = bin2hex(random_bytes(20));
        $ext  = $this->guessClientExtension() ?? $this->getClientOriginalExtension();
        return $ext ? "{$hash}.{$ext}" : $hash;
    }

    /**
     * The original filename as declared by the client (sanitised).
     */
    public function getOriginalName(): string
    {
        return $this->getClientOriginalName();
    }

    /**
     * Client-declared extension (lowercase, no dot).
     */
    public function getExtension(): string
    {
        return strtolower($this->getClientOriginalExtension());
    }

    /**
     * True if the upload succeeded and the file exists.
     */
    public function isValid(): bool
    {
        return parent::isValid() && $this->isFile();
    }

    /**
     * File size in kilobytes (rounded).
     */
    public function sizeInKb(): float
    {
        return round($this->getSize() / 1024, 2);
    }

    // ── Image helpers ────────────────────────────────────────────────

    /**
     * Returns [width, height] for images, or null if not an image / not readable.
     *
     * @return array{int,int}|null
     */
    public function dimensions(): ?array
    {
        if (!function_exists('getimagesize')) {
            return null;
        }
        $info = @getimagesize($this->getPathname());
        return ($info && $info[0] > 0 && $info[1] > 0) ? [$info[0], $info[1]] : null;
    }

    // ── Internal ─────────────────────────────────────────────────────

    private function storageRoot(): string
    {
        try {
            return \Marrow\Application::getInstance()->getBasePath('storage/app');
        } catch (\Throwable) {
            return sys_get_temp_dir() . '/marrow_uploads';
        }
    }
}
