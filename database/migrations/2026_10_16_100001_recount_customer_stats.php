<?php

use App\Services\Customers\CustomerStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer counts were kept only by the courier webhook, so imported orders and statuses changed by hand
 * were missing (the customer page said Delivered 0 while the journey said 1). Count every customer again
 * by the one rule now used everywhere (CustomerStats).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')->orderBy('id')->select('id')->chunk(1000, fn ($rows) => CustomerStats::refresh($rows->pluck('id')));
    }

    public function down(): void
    {
        // Nothing to take back: the counts are only made right.
    }
};
