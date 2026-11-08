<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // The lifecycle. Existing stores are live, so the default is 'active'; is_active stays the live switch
            // (a store the owner closes, or an operator suspends, has is_active = false).
            $table->string('status', 20)->default('active')->after('is_active'); // draft | pending_review | active | suspended
            $table->text('status_reason')->nullable()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('status_reason');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();

            $table->string('legal_name', 190)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('region', 60)->nullable();       // province the seller ships from
            $table->string('city', 100)->nullable();
            $table->text('return_policy')->nullable();
            $table->text('shipping_policy')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 40)->nullable();   // claimed by the seller, never verified here
            $table->string('registration_number', 60)->nullable(); // claimed by the seller
            $table->string('banner_path')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->index('status', 'stores_status_index');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32); // marketing | stock_alerts | price_alerts | ...
            $table->boolean('mail')->default(false);
            $table->boolean('in_app')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category'], 'notification_preferences_user_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex('stores_status_index');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn([
                'status', 'status_reason', 'submitted_at', 'reviewed_at', 'legal_name', 'contact_email', 'contact_phone',
                'region', 'city', 'return_policy', 'shipping_policy', 'vat_registered', 'vat_number',
                'registration_number', 'banner_path',
            ]);
        });
    }
};
