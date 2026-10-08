<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calls with customers: made from an order (tap to call, copy or QR) or
 * received (logged in Communication). Who, when, how it went, how long,
 * and the recording's link (uploaded to Drive by staff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 15)->nullable();
            $table->string('direction', 3); // out, in
            $table->foreignId('user_id')->constrained();
            $table->timestamp('started_at');
            $table->string('outcome', 20)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('recording_url', 500)->nullable();
            $table->string('note', 500)->nullable();
            $table->index(['user_id', 'started_at']);
            $table->index(['order_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_calls');
    }
};
