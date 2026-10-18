<?php

use App\Support\Shop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders remember the currency they were placed in, so changing
     * SHOP_CURRENCY later never relabels history.
     *
     * Every existing order is stamped with the storefront currency in force
     * RIGHT NOW. That is the best available answer: the old storefront printed
     * a bare dollar sign and never recorded a currency. It is a single
     * additive ADD COLUMN ... NOT NULL DEFAULT '<code>' (the database fills
     * the existing rows in the same statement, so there is no window where a
     * row has no currency, and no per-row update on a live table). No money,
     * status or other column is read or rewritten.
     *
     * The default is only a safety net for hand-written inserts: the Order
     * model stamps the storefront currency on every order it creates.
     *
     * An invalid SHOP_CURRENCY stops the migration BEFORE any change, so a
     * typo cannot stamp a wrong code onto the whole order history.
     *
     * BACK UP THE DATABASE FIRST: stamping history with a currency is not
     * something `down()` can restore if you later correct it by hand.
     */
    public function up(): void
    {
        $currency = Shop::currency();

        if (Schema::hasColumn('orders', 'currency')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) use ($currency) {
            $table->string('currency', 3)->default($currency)->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'currency')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
