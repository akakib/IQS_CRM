<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each turn of an order in someone's hands records when they first opened
 * it and when they first acted on it, so the time is measured without a
 * countdown. The countdown itself becomes optional and starts switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->timestamp('first_opened_at')->nullable()->after('started_at');
            $table->timestamp('first_action_at')->nullable()->after('first_opened_at');
        });

        // Timers running now stop: nothing counts down any more unless the admin switches it back on.
        DB::table('orders')->whereNotNull('action_due_at')->orWhereNotNull('timer_overran_at')->update(['action_due_at' => null, 'timer_overran_at' => null]);
        DB::table('order_work_logs')->whereNull('done_at')->update(['done_at' => now(), 'outcome' => 'timer_off']);
    }

    public function down(): void
    {
        Schema::table('order_assignments', fn (Blueprint $table) => $table->dropColumn(['first_opened_at', 'first_action_at']));
    }
};
