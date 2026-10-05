<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small advance (up to the "payments.trust_up_to" setting) lowers the COD at
 * once and is checked later on the Payments page, so the order never waits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->boolean('counts_now')->default(false)->after('status');
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'received_at']);
            $table->dropColumn('counts_now');
        });
    }
};
