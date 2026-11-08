<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Services\ProductImages;
use Illuminate\Console\Command;

class ImagesThumbnails extends Command
{
    protected $signature = 'emall:images-thumbnails {--limit=0 : Stop after this many photos (0 = all)}';

    protected $description = 'Make the card-size copy of listing photos uploaded before card pictures existed (safe to re-run)';

    public function handle(ProductImages $images): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $made = 0;
        $skipped = 0;

        ProductImage::query()->whereNull('thumb_path')->orderBy('id')->chunkById(100, function ($chunk) use ($images, $limit, &$made, &$skipped) {
            foreach ($chunk as $image) {
                $images->makeThumbnail($image) ? $made++ : $skipped++;

                if ($limit > 0 && $made >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $this->info("Made {$made} card pictures; {$skipped} photos left as they are (already small, missing or unreadable).");

        return self::SUCCESS;
    }
}
