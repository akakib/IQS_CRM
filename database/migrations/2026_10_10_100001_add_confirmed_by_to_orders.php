<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who an order counts for (KPI, profit): the person who held it when it was
 * confirmed (or confirmed it, when nobody held it), kept for good. A later
 * reassign moves the work, not the credit. Existing orders: the holder now,
 * else the person on the first confirm event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('orders')->whereNotNull('confirmed_at')->whereNotNull('moderator_id')->update(['confirmed_by' => DB::raw('moderator_id')]);
        $confirmed = DB::table('order_statuses')->where('system_key', 'confirmed')->value('id');
        if ($confirmed) {
            $first = DB::table('order_events')->where('to_status_id', $confirmed)->whereNotNull('user_id')
                ->groupBy('order_id')->selectRaw('order_id, MIN(id) as id')->pluck('id', 'order_id');
            $users = DB::table('order_events')->whereIn('id', $first->values())->pluck('user_id', 'order_id');
            foreach ($users as $orderId => $userId) {
                DB::table('orders')->where('id', $orderId)->whereNull('confirmed_by')->update(['confirmed_by' => $userId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
        });
    }
};
