<?php

namespace App\Services\Returns;

use App\Models\Order;
use App\Services\ProductImages;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos a buyer attaches to a return request (D-25 follow-up). Same discipline as review photos: JPEG, PNG or WebP decided by the
 * file's CONTENT (never its name or the browser's claim), a size limit, decoded and re-encoded (so EXIF and GPS are dropped and a file that
 * only looks like an image is refused), a random name, and the PRIVATE disk under returns/{order id}/ (the prefix ReturnService requires).
 * They are streamed by ReturnPhotoController to the buyer, the seller and the Emall team only.
 */
class ReturnPhotos
{
    public const DISK = 'local';

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_SIDE = 1600;

    /**
     * @param  array<int, mixed>  $files
     *
     * @throws ValidationException
     */
    public function validate(array $files): void
    {
        $files = array_values(array_filter($files));
        $max = (int) config('emall.returns.max_photos', 4);

        if (count($files) > $max) {
            throw ValidationException::withMessages(['photos' => "You can add up to {$max} photos."]);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['photos' => 'One of the photos did not upload. Try again, or leave it out.']);
            }

            if ($file->getSize() > (int) config('emall.returns.max_photo_kilobytes', 4096) * 1024) {
                throw ValidationException::withMessages(['photos' => 'Each photo can be at most 4 MB.']);
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

            $size = function_exists('imagecreatefromstring') ? @getimagesize($file->getRealPath()) : null;

            if (! in_array($mime, self::MIMES, true) || (function_exists('imagecreatefromstring') && $size === false)) {
                throw ValidationException::withMessages(['photos' => 'Photos must be JPEG, PNG or WebP images.']);
            }

            // Read from the header, before anything is decoded: a small file can describe a picture of hundreds of millions of pixels.
            if (is_array($size) && ($size[0] > ProductImages::MAX_SIDE || $size[1] > ProductImages::MAX_SIDE || $size[0] * $size[1] > ProductImages::MAX_PIXELS)) {
                throw ValidationException::withMessages(['photos' => 'One of the photos is too large. Photos can be at most '.ProductImages::MAX_SIDE.' pixels on a side.']);
            }
        }
    }

    /**
     * Stores the cleaned photos and returns their paths (all under returns/{order id}/).
     *
     * @param  array<int, mixed>  $files
     * @return list<string>
     */
    public function store(Order $order, array $files): array
    {
        $files = array_values(array_filter($files));
        $this->validate($files);
        $paths = [];

        try {
            foreach ($files as $file) {
                $path = 'returns/'.$order->id.'/'.Str::random(40).'.jpg';
                Storage::disk(self::DISK)->put($path, $this->clean($file));
                $paths[] = $path;
            }
        } catch (\Throwable $e) {
            $this->discard($paths);

            throw $e;
        }

        return $paths;
    }

    /** @param  list<string>  $paths */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function clean(UploadedFile $file): string
    {
        $bytes = (string) file_get_contents($file->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['photos' => 'One of the photos could not be read.']);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width * $height > 25_000_000) {
            throw ValidationException::withMessages(['photos' => 'One of the photos is too large.']);
        }

        if (max($width, $height) > self::MAX_SIDE) {
            $scale = self::MAX_SIDE / max($width, $height);
            $image = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) ?: $image;
        }

        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }
}
