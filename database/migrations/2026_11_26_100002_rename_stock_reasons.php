<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clearer names (2026-11-26): the hold for stock and the cancel for stock no longer sound alike.
 * Hold "Stock out" = Waiting for stock; Cancel "Out of stock" = the customer would not wait.
 * Only the default names are changed, so a name an admin set in Settings stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'stock_out')->where('label_en', 'Stock out')
            ->update(['label_en' => 'Waiting for stock', 'updated_at' => now()]);
        DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'out_of_stock')->where('label_en', 'Out of stock')
            ->update(['label_en' => "Out of stock, customer won't wait", 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'stock_out')->where('label_en', 'Waiting for stock')->update(['label_en' => 'Stock out']);
        DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'out_of_stock')->where('label_en', "Out of stock, customer won't wait")->update(['label_en' => 'Out of stock']);
    }
};
