<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A returned parcel received back at the shop: who, when, and each item's
 * state (good goes back on the shelf, damaged or missing does not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('return_received_at')->nullable();
            $table->foreignId('return_received_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('qty', 12, 3);
            $table->string('condition', 10); // good, damaged, missing
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_received_by');
            $table->dropColumn('return_received_at');
        });
    }
};
