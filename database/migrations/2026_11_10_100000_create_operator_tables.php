<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-43 W7, the operator desk. Strings, not enums, no database-specific features: this runs unchanged on SQLite and PostgreSQL.
 *
 *  operator_audit_log      append-only: who decided what, about whom, when and why (the model refuses update and delete)
 *  operator_access_log     POPIA: who opened a person's data in the support view, when and for which stated purpose
 *  reconcile_runs          what each emall:reconcile run found, so the ledger page shows a history and not a log line
 *  scheduled_task_runs     the last runs of the scheduled commands (success or failure), for the platform health page
 *  listing_flag_dismissals a flagged listing an operator looked at and judged fine, per rule, with a note
 *  featured_stores         the operator's editorial selection of stores (read by the homepage; never for sale)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 190)->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id'], 'operator_audit_subject_index');
            $table->index(['action', 'created_at'], 'operator_audit_action_index');
            $table->index('actor_id', 'operator_audit_actor_index');
            $table->index('created_at', 'operator_audit_time_index');
        });

        Schema::create('operator_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 190)->nullable();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 255)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id'], 'operator_access_subject_index');
            $table->index('created_at', 'operator_access_time_index');
        });

        Schema::create('reconcile_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            $table->unsignedInteger('days')->nullable();
            $table->unsignedInteger('checked')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->json('mismatches')->nullable();
            $table->string('trigger', 20)->default('manual');
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('ran_at', 'reconcile_runs_time_index');
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 190);
            $table->string('status', 12);
            $table->timestamp('ran_at');
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->string('note', 255)->nullable();

            $table->index(['task', 'ran_at'], 'scheduled_task_runs_task_index');
        });

        Schema::create('listing_flag_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('rule', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'rule'], 'listing_flag_dismissals_unique');
        });

        Schema::create('featured_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('note', 190)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('store_id', 'featured_stores_store_unique');
        });
    }

    public function down(): void
    {
        foreach (['featured_stores', 'listing_flag_dismissals', 'scheduled_task_runs', 'reconcile_runs', 'operator_access_log', 'operator_audit_log'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
