<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Schema;

/**
 * Covering for someone away: when a call-back is due (No response) or a hold date comes and the order's person is
 * on a break or off work, a free colleague gets it to call. covered_for_id keeps whose order it is (shown on the
 * desk; delivery points stay theirs); the colleague earns order_helped when they confirm it.
 */
return new class extends Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('covered_for_id')->nullable()->constrained('users')->nullOnDelete();
        });
        if (DB::getSchemaBuilder()->hasTable('point_rules') && DB::table('point_rules')->exists()
            && ! DB::table('point_rules')->where('trigger_key', 'order_helped')->exists()) {
            DB::table('point_rules')->insert([
                'trigger_key' => 'order_helped', 'name' => "Confirmed a colleague's order while they were away", 'points' => 3, 'recipient' => 'actor',
                'settle_on' => 'immediate', 'requires_delivery' => false, 'is_active' => true, 'sort_order' => 51, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('point_rules')->where('trigger_key', 'order_helped')->delete();
        \Illuminate\Support\Facades\Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('covered_for_id'));
    }
};
