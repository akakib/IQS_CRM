<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A call is also for upselling, so a trusted repeat customer is called too instead of being booked straight away. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('verification_rules')->where('name', 'Trusted repeat customer')->where('outcome', 'record_verified_and_confirmed')
            ->update(['outcome' => 'record_verified', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('verification_rules')->where('name', 'Trusted repeat customer')->update(['outcome' => 'record_verified_and_confirmed', 'updated_at' => now()]);
    }
};
