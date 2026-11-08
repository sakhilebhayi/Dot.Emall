<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('recipient_name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('line1', 160);
            $table->string('line2', 160)->nullable();
            $table->string('suburb', 100)->nullable();
            $table->string('city', 100);
            $table->string('province', 60)->nullable();
            $table->string('postal_code', 12);
            $table->string('country', 2)->default('ZA');
            $table->string('instructions', 255)->nullable();
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default_shipping'], 'addresses_user_default_shipping_index');
        });

        // Real data for the seller trust ladder: set only when a person signs in through InfoDot, and only when
        // InfoDot's own payload says a company is verified. Nothing else ever sets either.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('infodot_signed_in_at')->nullable();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('infodot_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('infodot_verified_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('infodot_signed_in_at'));
        Schema::dropIfExists('addresses');
    }
};
