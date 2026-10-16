<?php

use App\Services\Customers\CustomerStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of history on a customer. orders_count / delivered_count / returned_count stay the whole
 * picture (IQS orders and orders imported from files) for people to read. The iqs_* counts are only
 * what IQS itself saw (courier confirmed): verification rules, the fraud check and "new customer" use
 * these, so rows typed into an imported file can never make a customer look trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('iqs_orders_count')->default(0)->after('returned_count');
            $table->unsignedInteger('iqs_delivered_count')->default(0)->after('iqs_orders_count');
            $table->unsignedInteger('iqs_returned_count')->default(0)->after('iqs_delivered_count');
        });
        DB::table('customers')->orderBy('id')->select('id')->chunk(1000, fn ($rows) => CustomerStats::refresh($rows->pluck('id')));
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['iqs_orders_count', 'iqs_delivered_count', 'iqs_returned_count']);
        });
    }
};
