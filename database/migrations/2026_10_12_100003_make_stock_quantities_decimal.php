<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock in the unit it is sold in, with fractions: 0.9 of a "1 kg" pack that
 * came back good is 0.9 on the shelf, not a whole pack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('stock_qty', 12, 3)->nullable()->change();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('qty_change', 12, 3)->change();
            $table->decimal('qty_after', 12, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock_qty')->nullable()->change();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('qty_change')->change();
            $table->integer('qty_after')->change();
        });
    }
};
