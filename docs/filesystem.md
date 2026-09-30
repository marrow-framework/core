# Filesystem & Storage

`Marrow\Filesystem\Storage` wraps `league/flysystem` behind a small,
stable API — disks are the only thing that changes between environments,
call sites never need to know which adapter is behind a disk.

## Configuring disks

```php
// config/filesystems.php
return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
        ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
        ],
    ],
];
```

The `s3` driver requires an extra package:

```bash
composer require league/flysystem-aws-s3-v3
```

Using it without that package installed throws a `RuntimeException` with
that exact instruction, the moment the `s3` disk is first resolved.

## Usage

```php
use Marrow\Filesystem\Storage;

// Default disk (static proxies)
Storage::put('avatars/me.jpg', $contents);
Storage::get('avatars/me.jpg');
Storage::exists('avatars/me.jpg');
Storage::delete('avatars/me.jpg');
Storage::url('avatars/me.jpg');
Storage::files('avatars', recursive: true);

// A specific disk (instance methods — full API)
$disk = Storage::disk('public');
$disk->write('reports/2024.csv', $csv);
$disk->writeStream('videos/intro.mp4', $resource);
$disk->read('reports/2024.csv');
$disk->readStream('videos/intro.mp4');
$disk->has('reports/2024.csv');
$disk->remove('reports/2024.csv');
$disk->copy('a.txt', 'b.txt');
$disk->move('a.txt', 'archive/a.txt');
$disk->size('a.txt');
$disk->lastModified('a.txt');
$disk->mimeType('a.txt');
$disk->makeDirectory('archive');
$disk->publicUrl('reports/2024.csv');   // built from the disk's 'url' config
$disk->listFiles('reports', recursive: true);
```

Every instance method wraps its Flysystem call in a `try/catch`, returning
`false`/`''`/`0`/`[]` on failure rather than throwing — check the return
value if the operation's success matters to your logic.

## Uploaded files

`Http\UploadedFile` (from `$request->file(...)`) has its own storage
helpers, independent of the `Storage`/disk abstraction above:

```php
$file = $request->file('avatar');

$path = $file->store('avatars');            // storage/app/avatars/{hash}.{ext} — returns "avatars/{hash}.ext" or false
$path = $file->storeAs('avatars', 'me.jpg'); // storage/app/avatars/me.jpg

$file->hashName();                          // unique hash-based filename, original extension preserved
$file->sizeInKb();
$file->dimensions();                        // [width, height] for images, or null
```

`store()`/`storeAs()` move the file directly to `storage/app/{directory}`
via `SplFileInfo::move()` — they **don't** go through a configured
`Storage` disk, so they always land under `storage/app/` regardless of
`filesystems.php`. To store an upload on a specific disk (e.g. `s3`),
open it as a stream yourself instead:

```php
$stream = fopen($file->getRealPath(), 'r');
Storage::disk('s3')->writeStream('avatars/' . $file->hashName(), $stream);
```

See [Requests & Responses](http.md#file-uploads) and
[Validation](validation.md#rule-reference) for validating uploads before
you store them.
