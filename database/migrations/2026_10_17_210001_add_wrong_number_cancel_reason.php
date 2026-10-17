<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A call saved as Wrong number opens Cancel with this reason picked. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('status_reasons')->where('reason_type', 'cancel')->exists()
            && ! DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'wrong_number')->exists()) {
            DB::table('status_reasons')->insert([
                'reason_type' => 'cancel', 'label_en' => 'Wrong number', 'blame_stage' => 'customer', 'system_key' => 'wrong_number',
                'release_mode' => 'manual', 'counts_as_break' => true, 'is_active' => true,
                'sort_order' => (int) DB::table('status_reasons')->where('reason_type', 'cancel')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'wrong_number')->delete();
    }
};
