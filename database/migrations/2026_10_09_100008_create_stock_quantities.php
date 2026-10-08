<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many of each variant are on the shelf, and every change with its reason.
 * stock_qty null = not counted yet (nothing moves for it). Packing takes away,
 * putting a box back or a good item from a return brings back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock_qty')->nullable()->after('availability_status');
            $table->timestamp('stock_counted_at')->nullable()->after('stock_qty');
            // 'stock': the shelf count put it Out of stock (and may bring it back).
            $table->enum('availability_source', ['manual', 'auto', 'stock'])->nullable()->change();
        });
        Schema::table('availability_events', function (Blueprint $table) {
            $table->enum('source', ['manual', 'auto', 'stock'])->default('manual')->change();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->integer('qty_change');
            $table->integer('qty_after');
            $table->string('reason', 20); // count, packed, unpacked, return_good, damaged, adjust
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['variant_id', 'id']);
            $table->index(['order_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        DB::table('product_variants')->where('availability_source', 'stock')->update(['availability_source' => 'auto']);
        DB::table('availability_events')->where('source', 'stock')->update(['source' => 'auto']);
        Schema::table('availability_events', function (Blueprint $table) {
            $table->enum('source', ['manual', 'auto'])->default('manual')->change();
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['stock_qty', 'stock_counted_at']);
            $table->enum('availability_source', ['manual', 'auto'])->nullable()->change();
        });
    }
};
