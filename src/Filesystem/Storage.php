<?php

declare(strict_types=1);

namespace Marrow\Filesystem;

use Marrow\Application;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Visibility;

/**
 * Filesystem abstraction backed by league/flysystem.
 *
 * Disks are configured in config/filesystems.php. Each disk maps to a
 * Flysystem adapter (local, public, or s3 when the S3 package is installed).
 *
 * Usage:
 *   Storage::put('avatars/me.jpg', $contents);
 *   Storage::disk('s3')->url('avatars/me.jpg');
 *   Storage::exists('avatars/me.jpg');
 *   Storage::delete('avatars/me.jpg');
 *
 * The public surface is the same as before — existing call sites keep working —
 * but the engine underneath is now a mature, multi-cloud library.
 */
class Storage
{
    /** @var array<string, Filesystem> Cached adapters by disk name. */
    private static array $disks = [];

    private function __construct(
        private readonly string $diskName,
        private readonly Filesystem $fs,
        private readonly array $diskConfig
    ) {
    }

    // ── Disk selection ───────────────────────────────────────────────

    /** @throws \RuntimeException If $name (or the default disk) is configured for `s3` but league/flysystem-aws-s3-v3 isn't installed. */
    public static function disk(?string $name = null): static
    {
        $name ??= self::defaultDiskName();

        if (!isset(self::$disks[$name])) {
            self::$disks[$name] = self::buildFilesystem($name);
        }

        return new static($name, self::$disks[$name], self::diskConfigFor($name));
    }

    /** The name of the disk this instance was resolved for. */
    public function name(): string
    {
        return $this->diskName;
    }

    // ── Static proxies (default disk) ────────────────────────────────

    public static function put(string $path, mixed $contents): bool
    {
        return static::disk()->write($path, $contents);
    }

    public static function get(string $path): string|false
    {
        return static::disk()->read($path);
    }

    public static function exists(string $path): bool
    {
        return static::disk()->has($path);
    }

    public static function delete(string $path): bool
    {
        return static::disk()->remove($path);
    }

    public static function url(string $path): string
    {
        return static::disk()->publicUrl($path);
    }

    public static function files(string $directory = '', bool $recursive = false): array
    {
        return static::disk()->listFiles($directory, $recursive);
    }

    // ── Instance methods ─────────────────────────────────────────────

    public function write(string $path, mixed $contents): bool
    {
        try {
            $this->fs->write($path, (string) $contents);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param resource $resource */
    public function writeStream(string $path, $resource): bool
    {
        try {
            $this->fs->writeStream($path, $resource);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(string $path): string|false
    {
        try {
            return $this->fs->read($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return resource|false */
    public function readStream(string $path)
    {
        try {
            return $this->fs->readStream($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public function has(string $path): bool
    {
        try {
            return $this->fs->fileExists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public function remove(string $path): bool
    {
        try {
            $this->fs->delete($path);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function copy(string $from, string $to): bool
    {
        try {
            $this->fs->copy($from, $to);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function move(string $from, string $to): bool
    {
        try {
            $this->fs->move($from, $to);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function size(string $path): int
    {
        try {
            return $this->fs->fileSize($path);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function lastModified(string $path): int
    {
        try {
            return $this->fs->lastModified($path);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function mimeType(string $path): string
    {
        try {
            return $this->fs->mimeType($path);
        } catch (\Throwable) {
            return '';
        }
    }

    public function makeDirectory(string $path): bool
    {
        try {
            $this->fs->createDirectory($path);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function publicUrl(string $path): string
    {
        $base = rtrim((string) ($this->diskConfig['url'] ?? '/storage'), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public function listFiles(string $directory = '', bool $recursive = false): array
    {
        try {
            return $this->fs->listContents($directory, $recursive)
                ->filter(fn ($item) => $item->isFile())
                ->map(fn ($item) => $item->path())
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Internal: build adapters from config ─────────────────────────

    private static function buildFilesystem(string $name): Filesystem
    {
        $config = self::diskConfigFor($name);
        $driver = $config['driver'] ?? 'local';

        $adapter = match ($driver) {
            's3'    => self::makeS3Adapter($config),
            default => new LocalFilesystemAdapter(
                $config['root'] ?? self::defaultRoot(),
                visibility: \League\Flysystem\UnixVisibility\PortableVisibilityConverter::fromArray([], Visibility::PRIVATE)
            ),
        };

        return new Filesystem($adapter);
    }

    private static function makeS3Adapter(array $config): object
    {
        $s3Adapter = 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter';
        $s3Client  = 'Aws\\S3\\S3Client';

        if (!class_exists($s3Adapter)) {
            throw new \RuntimeException(
                'S3 disk requires league/flysystem-aws-s3-v3. Run: composer require league/flysystem-aws-s3-v3'
            );
        }

        $client = new $s3Client([
            'version'     => 'latest',
            'region'      => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key'    => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
            'endpoint'    => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style'] ?? false),
        ]);

        return new $s3Adapter($client, $config['bucket'] ?? '', $config['prefix'] ?? '');
    }

    private static function diskConfigFor(string $name): array
    {
        $config = Application::getInstance()->getContainer()->make(\Marrow\Config\Repository::class);
        return (array) $config->get("filesystems.disks.{$name}", []);
    }

    private static function defaultDiskName(): string
    {
        $config = Application::getInstance()->getContainer()->make(\Marrow\Config\Repository::class);
        return (string) $config->get('filesystems.default', 'local');
    }

    private static function defaultRoot(): string
    {
        return Application::getInstance()->path('storage', 'app');
    }
}
