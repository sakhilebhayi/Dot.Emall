<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A card-size copy (at most 640 px on its long edge) of each listing photo, so a grid of 24 cards does not download 24 full-size
 * photos. Null on photos uploaded before this change: they keep serving the full-size file until `emall:images-thumbnails` makes the copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_images', 'thumb_path')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->string('thumb_path')->nullable()->after('path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_images', 'thumb_path')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropColumn('thumb_path');
            });
        }
    }
};
