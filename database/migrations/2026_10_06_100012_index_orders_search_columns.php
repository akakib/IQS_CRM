<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The order list searches by phone and name with LIKE 'x%': without these, every search scanned all orders (150 ms on 200k). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('ship_phone');
            $table->index('ship_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['ship_phone']);
            $table->dropIndex(['ship_name']);
        });
    }
};
