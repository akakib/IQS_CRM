<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The courier CN of an imported parcel, in one shape (upper case, no spaces), so search finds it and it never comes in twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_past_orders', function (Blueprint $table) {
            $table->string('cn', 40)->nullable()->unique()->after('external_no');
        });
    }

    public function down(): void
    {
        Schema::table('customer_past_orders', function (Blueprint $table) {
            $table->dropUnique(['cn']);
            $table->dropColumn('cn');
        });
    }
};
