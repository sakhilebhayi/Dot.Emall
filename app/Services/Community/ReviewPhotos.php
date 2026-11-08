<?php

namespace App\Services\Community;

use App\Models\Review;
use App\Models\ReviewPhoto;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos on a review. Up to four, JPEG, PNG or WebP, 4 MB each. Each is decoded and re-encoded (so EXIF and GPS
 * are dropped and a file that only looks like an image is refused) and written to the PRIVATE disk under a random
 * name, never the uploader's. They are not served from a public URL: ReviewPhotoController streams one only when
 * the review is published and its listing is visible (or to its author and the operator).
 */
class ReviewPhotos
{
    public const DISK = 'local';

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_SIDE = 1600;

    /**
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function validate(array $files, int $alreadyAttached = 0): void
    {
        $files = array_values(array_filter($files));
        $max = (int) config('discovery.reviews.max_photos', 4);

        if ($files === []) {
            return;
        }

        if (count($files) + $alreadyAttached > $max) {
            throw ValidationException::withMessages(['photos' => "A review can have up to {$max} photos."]);
        }

        foreach ($files as $file) {
            if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw ValidationException::withMessages(['photos' => 'Photos can be at most 4 MB each.']);
            }

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['photos' => 'One of the photos did not upload. Try again.']);
            }

            if ($file->getSize() > (int) config('discovery.reviews.max_photo_kilobytes', 4096) * 1024) {
                throw ValidationException::withMessages(['photos' => 'Photos can be at most 4 MB each.']);
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

            if (! in_array($mime, self::MIMES, true) || ($this->gd() && @getimagesize($file->getRealPath()) === false)) {
                throw ValidationException::withMessages(['photos' => 'Photos must be JPEG, PNG or WebP images.']);
            }
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<ReviewPhoto>
     */
    public function attach(Review $review, array $files): array
    {
        $files = array_values(array_filter($files));
        $this->validate($files, $review->photos()->count());
        $order = (int) $review->photos()->max('sort_order');
        $out = [];

        foreach ($files as $file) {
            $path = 'review-photos/'.$review->id.'/'.Str::random(40).'.jpg';
            Storage::disk(self::DISK)->put($path, $this->clean($file));
            $out[] = ReviewPhoto::query()->create(['review_id' => $review->id, 'path' => $path, 'sort_order' => ++$order]);
        }

        return $out;
    }

    public function purge(Review $review): void
    {
        foreach ($review->photos()->get() as $photo) {
            Storage::disk(self::DISK)->delete($photo->path);
        }

        Storage::disk(self::DISK)->deleteDirectory('review-photos/'.$review->id);
    }

    private function gd(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /** Decoded and re-encoded as JPEG, longest side 1600, metadata gone. Without GD the validated bytes are kept. */
    private function clean(UploadedFile $file): string
    {
        $bytes = (string) file_get_contents($file->getRealPath());

        if (! $this->gd()) {
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

        // A flat white background so a transparent PNG does not turn black as a JPEG.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }
}
