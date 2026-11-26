<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock out / pre-order (2026-11-26): an order with such an item is no longer held by the system on arrival;
 * it goes through Verify and Call with an orange flag, and it cannot be confirmed (go to packaging) unless an
 * admin allows it (stock_waiver_*), like an order without its advance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_waiver_requested_at')->nullable();
            $table->foreignId('stock_waiver_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stock_waiver_note', 300)->nullable();
            $table->foreignId('stock_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stock_waived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_waiver_requested_by');
            $table->dropConstrainedForeignId('stock_waived_by');
            $table->dropColumn(['stock_waiver_requested_at', 'stock_waiver_note', 'stock_waived_at']);
        });
    }
};
