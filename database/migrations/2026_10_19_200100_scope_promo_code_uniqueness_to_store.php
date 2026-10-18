<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * promo_codes.code was unique across the whole marketplace even though
     * a promo is one store's own coupon: two stores could not both run
     * "SUMMER10", and a failed attempt to create one revealed that another
     * store already had it. Uniqueness is now (store_id, code).
     *
     * Codes are matched case-insensitively and have always been stored
     * upper-case by the controller; this upper-cases any stragglers. A row
     * is left exactly as it is if upper-casing it would collide with
     * another code in the same store, so no coupon is ever merged away.
     * Usage counts are untouched.
     */
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });

        DB::table('promo_codes')->orderBy('id')->each(function ($row) {
            $upper = mb_strtoupper($row->code);

            if ($upper === $row->code) {
                return;
            }

            $collides = DB::table('promo_codes')
                ->where('store_id', $row->store_id)
                ->where('id', '!=', $row->id)
                ->whereRaw('UPPER(code) = ?', [$upper])
                ->exists();

            if (! $collides) {
                DB::table('promo_codes')->where('id', $row->id)->update(['code' => $upper]);
            }
        });

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->unique(['store_id', 'code'], 'promo_codes_store_id_code_unique');
        });
    }

    public function down(): void
    {
        // Restoring global uniqueness could fail on data that is now valid,
        // so rolling back only drops the per-store rule.
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropUnique('promo_codes_store_id_code_unique');
        });
    }
};
