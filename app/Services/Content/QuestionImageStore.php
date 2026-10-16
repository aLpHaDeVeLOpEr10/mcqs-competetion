<?php

namespace App\Services\Content;

use App\Exceptions\ContentValidationException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores question images safely: the upload is decoded by GD and re-encoded
 * as WebP (dropping metadata and any non-image payload), saved under a
 * random server-chosen name on the private disk, and never executed or
 * served from the public web root.
 */
class QuestionImageStore
{
    public function store(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new ContentValidationException('The image could not be read. Upload a JPEG, PNG or WebP file.');
        }

        $max = (int) config('competition.questions.image_max_dimension');
        if (imagesx($image) > $max || imagesy($image) > $max) {
            imagedestroy($image);
            throw new ContentValidationException("The image may be at most {$max}×{$max} pixels.");
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, 85);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        $path = config('competition.questions.image_directory').'/'.Str::random(40).'.webp';
        $this->disk()->put($path, $encoded);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $this->isManagedPath($path)) {
            $this->disk()->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $this->isManagedPath($path) && $this->disk()->exists($path);
    }

    public function response(string $path): StreamedResponse
    {
        abort_unless($this->exists($path), 404);

        return $this->disk()->response($path, null, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /**
     * Only paths this class generated are ever read or deleted.
     */
    protected function isManagedPath(string $path): bool
    {
        $directory = preg_quote(config('competition.questions.image_directory'), '#');

        return (bool) preg_match("#^{$directory}/[A-Za-z0-9]{40}\.webp$#", $path);
    }

    protected function disk()
    {
        return Storage::disk(config('competition.questions.image_disk'));
    }
}
