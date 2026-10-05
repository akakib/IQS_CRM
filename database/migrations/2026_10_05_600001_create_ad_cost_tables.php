<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta / Google ad cost in real taka. Dollars are bought in lots from
 * vendors; daily ad spend uses the lots oldest first (FIFO), so each day
 * gets its real BDT cost. Vendor payments are append-only; allocations are
 * derived data and are rebuilt when a re-pull changes an earlier day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 15)->nullable();
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('usd_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('ad_vendors');
            $table->date('purchased_on');
            $table->decimal('usd', 12, 2);
            $table->decimal('rate', 10, 4);                          // BDT per USD
            $table->decimal('bdt_total', 12, 2);
            $table->decimal('usd_remaining', 12, 2);                 // FIFO balance
            $table->date('due_date')->nullable();                    // when not paid in full now
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['purchased_on', 'id']);
            $table->index(['vendor_id', 'purchased_on']);
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('ad_vendors');
            $table->foreignId('lot_id')->nullable()->constrained('usd_lots')->nullOnDelete();
            $table->decimal('amount_bdt', 12, 2);
            $table->date('paid_on');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('transaction_ref', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['vendor_id', 'paid_on']);
        });

        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->enum('platform', ['meta', 'google']);
            $table->string('name', 120);
            $table->string('external_id', 60)->nullable();           // act_123… / customer id
            $table->string('timezone', 40)->default('Asia/Dhaka');   // days follow the ad account
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ad_spend_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained();
            $table->date('spend_date');
            $table->decimal('spend_usd', 12, 2);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('messages')->default(0);         // messaging conversations started
            $table->unsignedInteger('purchases')->default(0);        // as reported by the platform
            $table->decimal('reported_value', 12, 2)->default(0);    // purchase value reported by the platform (BDT)
            $table->enum('source', ['api', 'import', 'manual', 'fake']);
            $table->decimal('bdt_cost', 12, 2)->nullable();          // from FIFO allocation
            $table->decimal('unfunded_usd', 12, 2)->default(0);      // spend with no lot left to cover it
            $table->timestamps();
            $table->unique(['ad_account_id', 'spend_date']);
            $table->index('spend_date');
        });

        Schema::create('usd_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_id')->constrained('ad_spend_daily')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('usd_lots');
            $table->decimal('usd', 12, 2);
            $table->decimal('bdt', 12, 2);
            $table->index('spend_id');
            $table->index('lot_id');
        });

        // One row per day: that day's ad cost shared over the orders placed that day (P&L).
        Schema::create('ad_cost_days', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->decimal('bdt_cost', 12, 2);
            $table->unsignedInteger('orders');
            $table->decimal('per_order', 12, 2);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['ad_cost_days', 'usd_lot_allocations', 'ad_spend_daily', 'ad_accounts', 'vendor_payments', 'usd_lots', 'ad_vendors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
