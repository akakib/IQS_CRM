<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An advance on an order that was cancelled or returned is given back, or kept
 * for the customer's next order. Kept money is the customer's credit: a line
 * per change (+ kept from an order, - used on a new one), and the balance.
 * "Customer credit" is a payment method of its own (not offered for typing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_balance', 12, 2)->default(0);
        });

        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2); // + kept, - used
            $table->decimal('balance_after', 12, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'id']);
        });

        if (! DB::table('payment_methods')->where('system_key', 'credit')->exists()) {
            DB::table('payment_methods')->insert(['system_key' => 'credit', 'name' => 'Customer credit', 'requires_trx_id' => false, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }
};
