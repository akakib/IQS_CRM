<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advance hold as a call task: when it started (for reminders and the
 * no-advance cancel), when the hold date was announced. Website state: the
 * last status / paid time / total seen from WooCommerce, so only a real
 * change (pending -> paid, -> cancelled) does anything, however many times
 * the same webhook arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('advance_hold_since')->nullable();
            $table->timestamp('advance_reminded_at')->nullable();
            $table->timestamp('hold_date_notified_at')->nullable();
            $table->string('external_status', 30)->nullable();
            $table->timestamp('external_paid_at')->nullable();
            $table->decimal('external_total', 12, 2)->nullable();
        });
        // Orders already on advance hold: count from when they were held.
        $hold = DB::table('order_statuses')->where('system_key', 'hold')->value('id');
        $reason = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id');
        if ($hold && $reason) {
            foreach (DB::table('orders')->where('status_id', $hold)->where('hold_reason_id', $reason)->pluck('id') as $id) {
                $since = DB::table('order_events')->where('order_id', $id)->where('to_status_id', $hold)->max('created_at');
                DB::table('orders')->where('id', $id)->update(['advance_hold_since' => $since ?? now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['advance_hold_since', 'advance_reminded_at', 'hold_date_notified_at', 'external_status', 'external_paid_at', 'external_total']);
        });
    }
};
