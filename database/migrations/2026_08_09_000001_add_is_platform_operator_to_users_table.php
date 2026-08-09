<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately NOT named "is_admin" -- a generic admin flag
            // name is an obvious mass-assignment target to probe for.
            // Scoped naming plus exclusion from $fillable (see User model)
            // means no request payload can ever set this, regardless of
            // name. Matches ChartSense's identical column, added for the
            // same reason (an independent human reviewer for an
            // automated-proposal-awaiting-approval gate).
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
