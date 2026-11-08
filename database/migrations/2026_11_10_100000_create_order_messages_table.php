<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a buyer wrote about an order (R-43 W5): either to the seller of one parcel ("seller") or to the Emall team ("operator", the
 * report-a-problem queue). The sender's name and e-mail are kept as they were so a guest can be answered; nothing else about the
 * buyer is copied. A row is never edited; `handled_at` is the operator's note that someone dealt with a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_group_id')->nullable()->constrained('order_groups')->nullOnDelete();
            $table->string('kind', 12);
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_name', 120);
            $table->string('sender_email', 190);
            $table->text('body');
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'handled_at'], 'order_messages_queue_index');
            $table->index(['order_id', 'kind'], 'order_messages_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_messages');
    }
};
