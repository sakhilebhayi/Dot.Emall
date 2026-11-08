<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Listing photos. Up to six per product, the lowest sort_order is the cover.
 *
 * What is checked, in order: the request layer (SellerProductController /
 * ProductImageController) enforces file, type by CONTENT (finfo, not the
 * extension), and the 4 MB cap. This service then decodes every file with
 * GD -- a file that merely starts with image bytes is refused -- and bounds
 * its dimensions (100-6000 px a side, 25 megapixels) before anything is
 * written, so a decompression bomb never gets as far as memory.
 *
 * What is stored: never the upload itself. Each image is decoded and
 * re-encoded by GD, which drops EXIF/GPS and every other metadata block,
 * applies the camera's orientation first, and scales anything larger than
 * 1600 px on its long edge down (small images are never enlarged). The file
 * goes to the PUBLIC disk (these are listing photos, meant to be seen) under
 * a random name, never the uploader's filename.
 *
 * Known limit: if the PHP GD extension is not installed the service cannot
 * decode or re-encode, so the validated upload is stored unmodified and its
 * metadata is NOT stripped. GD is a standard requirement of this stack;
 * check `php -m | grep gd` on the server.
 */
class ProductImages
{
    public const MAX_PER_PRODUCT = 6;

    public const MAX_KILOBYTES = 4096;

    public const MIN_SIDE = 100;

    public const MAX_SIDE = 6000;

    public const MAX_PIXELS = 25_000_000;

    public const OUTPUT_MAX_SIDE = 1600;

    /** The card picture: a grid of 24 listings must not download 24 full-size photos. A card is drawn at most 480 px wide. */
    public const THUMB_MAX_SIDE = 640;

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Validation rules for an `images` input, shared by the create form and
     * the upload endpoint.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(int $max = self::MAX_PER_PRODUCT): array
    {
        return [
            'images' => ['nullable', 'array', 'max:'.$max],
            'images.*' => ['file', 'max:'.self::MAX_KILOBYTES, 'mimetypes:'.implode(',', self::MIMES), 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, ProductImage>
     *
     * @throws ValidationException
     */
    public function add(Product $product, array $files): Collection
    {
        $files = array_values(array_filter($files));

        if ($files === []) {
            return collect();
        }

        // Decode and re-encode everything first: if any file is bad nothing
        // is written at all.
        $prepared = [];
        foreach ($files as $index => $file) {
            $prepared[$index] = $this->prepare($file, $index);
        }

        $written = [];

        try {
            return DB::transaction(function () use ($product, $prepared, &$written) {
                // Serialise concurrent uploads to one product so the six-photo
                // cap cannot be raced past.
                Product::query()->lockForUpdate()->findOrFail($product->id);

                $existing = ProductImage::query()->where('product_id', $product->id)->count();

                if ($existing + count($prepared) > self::MAX_PER_PRODUCT) {
                    $room = max(0, self::MAX_PER_PRODUCT - $existing);

                    throw ValidationException::withMessages([
                        'images' => 'A listing can have at most '.self::MAX_PER_PRODUCT." photos. It has {$existing}, so you can add {$room} more.",
                    ]);
                }

                $next = (int) (ProductImage::query()->where('product_id', $product->id)->max('sort_order') ?? -1) + 1;
                $created = collect();

                foreach ($prepared as [$binary, $extension, $thumb]) {
                    $name = Str::random(40);
                    $path = "product-images/{$product->id}/{$name}.{$extension}";
                    Storage::disk('public')->put($path, $binary);
                    $written[] = $path;

                    $thumbPath = null;

                    if ($thumb !== null) {
                        $thumbPath = "product-images/{$product->id}/{$name}-card.{$extension}";
                        Storage::disk('public')->put($thumbPath, $thumb);
                        $written[] = $thumbPath;
                    }

                    $created->push(ProductImage::create([
                        'product_id' => $product->id,
                        'path' => $path,
                        'thumb_path' => $thumbPath,
                        'sort_order' => $next++,
                    ]));
                }

                return $created;
            });
        } catch (\Throwable $e) {
            // The transaction rolled the rows back; take the files with them.
            Storage::disk('public')->delete($written);

            throw $e;
        }
    }

    /**
     * Makes the card-size copy of a photo that has none (uploaded before card pictures existed). Returns whether a copy was made; a missing
     * or unreadable file is skipped, never fatal, so a backfill over thousands of photos always finishes.
     */
    public function makeThumbnail(ProductImage $image): bool
    {
        $disk = Storage::disk('public');

        if ($image->thumb_path !== null || ! $disk->exists($image->path) || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $contents = (string) $disk->get($image->path);
        $info = @getimagesizefromstring($contents);
        $source = @imagecreatefromstring($contents);

        if ($info === false || ! $source instanceof GdImage || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return false;
        }

        if (max($info[0], $info[1]) <= self::THUMB_MAX_SIDE) {
            // Already card-sized: the photo is its own card picture. Recorded, so a re-run does not read it again.
            $image->forceFill(['thumb_path' => $image->path])->save();

            return false;
        }

        [$binary, $extension] = $this->encode($this->scaleDown($source, self::THUMB_MAX_SIDE), $info[2]);
        $thumbPath = preg_replace('/\.[A-Za-z0-9]+$/', '', $image->path).'-card.'.$extension;
        $disk->put($thumbPath, $binary);
        $image->forceFill(['thumb_path' => $thumbPath])->save();

        return true;
    }

    /**
     * One picture for a store (its logo or banner): the same checks and re-encoding as a listing photo, written to the public
     * disk under "store-media/{store id}/" with a random name. Returns the stored path; the caller removes the one it replaces.
     *
     * @throws ValidationException
     */
    public function storeMedia(UploadedFile $file, int $storeId, string $field): string
    {
        [$binary, $extension] = $this->prepare($file, 0, withThumb: false);

        // prepare() reports against "images.0"; the form field is "logo" or "banner".
        $path = "store-media/{$storeId}/{$field}-".Str::random(24).".{$extension}";
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    public function remove(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            Product::query()->withTrashed()->lockForUpdate()->find($image->product_id);

            Storage::disk('public')->delete(array_filter([$image->path, $image->thumb_path]));
            $image->delete();

            $this->renumber($image->product_id);
        });
    }

    /**
     * Makes this photo the cover, keeping the others in their existing order.
     */
    public function makeCover(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            Product::query()->withTrashed()->lockForUpdate()->find($image->product_id);

            $this->renumber($image->product_id, first: $image->id);
        });
    }

    /**
     * Deletes every photo of a product: files first, then rows. Called when
     * a listing is deleted, so no orphaned files are left on the disk.
     */
    public function purge(Product $product): void
    {
        $images = ProductImage::query()->where('product_id', $product->id)->get();

        Storage::disk('public')->delete($images->pluck('path')->merge($images->pluck('thumb_path'))->filter()->values()->all());
        ProductImage::query()->where('product_id', $product->id)->delete();
    }

    private function renumber(int $productId, ?int $first = null): void
    {
        $ids = ProductImage::query()->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->pluck('id');

        if ($first !== null) {
            $ids = $ids->reject(fn ($id) => $id === $first)->prepend($first)->values();
        }

        foreach ($ids as $position => $id) {
            ProductImage::query()->where('id', $id)->update(['sort_order' => $position]);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [binary, extension, card-size binary or null]
     *
     * @throws ValidationException
     */
    private function prepare(UploadedFile $file, int $index, bool $withThumb = true): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(["images.{$index}" => $message]);
        $name = $file->getClientOriginalName();

        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $fail("\"{$name}\" could not be read as a JPEG, PNG or WebP image.");
        }

        [$width, $height, $type] = $info;

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            $fail("\"{$name}\" is too small: photos must be at least ".self::MIN_SIDE.' pixels on each side.');
        }

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            $fail("\"{$name}\" is too large: photos can be at most ".self::MAX_SIDE.' pixels on a side.');
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            return [$contents, [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$type], null];
        }

        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            $fail("\"{$name}\" looks like an image but could not be decoded. Re-export it and try again.");
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $contents);
        }

        $image = $this->scaleDown($image);

        [$binary, $extension] = $this->encode($image, $type);

        // The card picture is cut from the picture just decoded, so it is the same orientation and carries no metadata either.
        $thumb = null;

        if ($withThumb && max(imagesx($image), imagesy($image)) > self::THUMB_MAX_SIDE) {
            $small = $this->scaleDown($image, self::THUMB_MAX_SIDE);
            $thumb = $this->encode($small, $type)[0];
        }

        return [$binary, $extension, $thumb];
    }

    private function applyExifOrientation(GdImage $image, string $contents): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function scaleDown(GdImage $image, int $limit = self::OUTPUT_MAX_SIDE): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $long = max($width, $height);

        if ($long <= $limit) {
            return $image;
        }

        $scale = $limit / $long;
        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function encode(GdImage $image, int $type): array
    {
        ob_start();

        if ($type === IMAGETYPE_PNG) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 6);
            $extension = 'png';
        } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagewebp($image, null, 82);
            $extension = 'webp';
        } elseif ($type === IMAGETYPE_WEBP) {
            // A GD build without WebP encoding: keep transparency by going to PNG.
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 6);
            $extension = 'png';
        } else {
            imagejpeg($image, null, 85);
            $extension = 'jpg';
        }

        return [(string) ob_get_clean(), $extension];
    }
}
