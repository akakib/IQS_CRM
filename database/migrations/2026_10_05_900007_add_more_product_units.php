<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Products are not only pieces and grams: KG, ML, litre and packet are sold too. */
return new class extends Migration
{
    private const ALL = ['pcs', 'packet', 'box', 'g', 'kg', 'ml', 'l'];

    public function up(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->enum('unit', self::ALL)->default('pcs')->change());
        Schema::table('order_items', fn (Blueprint $t) => $t->enum('unit', self::ALL)->change());
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->enum('unit', ['pcs', 'g', 'box'])->default('pcs')->change());
        Schema::table('order_items', fn (Blueprint $t) => $t->enum('unit', ['pcs', 'g', 'box'])->change());
    }
};
