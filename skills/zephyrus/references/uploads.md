# Uploads

Read this when a form or an API accepts files.

`$request->file('avatar')` returns a `Zephyrus\Upload\FileUpload` (or `null`
when the field is absent); `$request->filesOf('photos')` returns a list for a
multiple field. `Uploader` stores one under a root directory and returns the
path relative to that root:

```php
use Zephyrus\Upload\Uploader;
use Zephyrus\Upload\UploadException;

$uploader = new Uploader(
    $root . '/storage/uploads',
    allowedExtensions: ['jpg', 'jpeg', 'png', 'webp'],
    allowedMimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
    maxSizeBytes: 5 * 1024 * 1024,
);

try {
    $path = $uploader->store($request->file('avatar'), 'avatars'); // "avatars/<32 hex>.png"
} catch (UploadException $e) {
    // wrong type, too large, not a genuine upload, path escape...
}
```

- Nothing the browser sends decides acceptance: the MIME allowlist is matched
  against the type sniffed from the bytes (`ext-fileinfo`), the size against
  the real file, and the stored extension comes from the sniffed type. A PHP
  file named `shell.png` is refused: `Upload MIME type "text/x-php" is not
  allowed. Allowed MIME types: image/png`.
- The name is random hex unless you pass a third argument (`$targetName`),
  which goes through the same extension allowlist. An existing file is only
  replaced with `overwriteExisting: true`.
- *(dev)* The default mover accepts only a genuine HTTP upload
  (`is_uploaded_file()`). Tests and command-line code inject their own:

```php
$uploader = new Uploader(
    $root . '/storage/uploads',
    allowedMimeTypes: ['image/png'],
    fileMover: fn (string $from, string $to): bool => copy($from, $to),
);
```

  Without it, a test gets `Refusing to move "...": it is not a genuine PHP
  upload. Inject a $fileMover when storing files that did not arrive over HTTP.`
- Keep the storage root outside `public/` and serve files through a controller
  that checks access. `MaxBodySizeMiddleware` only sees `Content-Length` for a
  multipart body: set `upload_max_filesize` and `post_max_size` as well.
