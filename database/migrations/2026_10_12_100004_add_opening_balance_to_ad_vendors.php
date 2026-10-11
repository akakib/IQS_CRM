<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a dollar vendor stood before the app: plus = we owed them,
 * minus = they held our advance. Counted first in the vendor's ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_vendors', function (Blueprint $table) {
            $table->decimal('opening_balance', 12, 2)->default(0)->after('note');
            $table->date('opening_on')->nullable()->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('ad_vendors', function (Blueprint $table) {
            $table->dropColumn(['opening_balance', 'opening_on']);
        });
    }
};
