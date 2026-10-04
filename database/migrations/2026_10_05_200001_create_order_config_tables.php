<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB_DESIGN 1.1 and 1.5: everything the business may change is a row.
 * Code depends only on system_key; custom statuses can be added freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 50)->nullable()->unique(); // NULL for custom statuses
            $table->string('name_en', 100);
            $table->string('name_bn', 100)->nullable();
            $table->string('color', 20)->default('#6b7280');
            $table->enum('stage_group', ['intake', 'verification', 'fulfillment', 'courier', 'final', 'side']);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_final')->default(false);
            $table->boolean('requires_reason')->default(false);
            $table->enum('edit_policy', ['free', 'approval', 'locked'])->default('free');
            $table->boolean('counts_as_sale')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('order_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_status_id')->constrained('order_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('order_statuses')->cascadeOnDelete();
            $table->string('permission_key', 100)->nullable(); // e.g. orders.edit
            $table->boolean('requires_reason')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('system_only')->default(false);     // only rules / webhooks / scans
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['from_status_id', 'to_status_id']);
        });

        Schema::create('status_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $table->enum('reason_type', ['status', 'amendment', 'return', 'cancel', 'hold', 'refund', 'reassign']);
            $table->string('label_en', 150);
            $table->string('label_bn', 150)->nullable();
            $table->enum('blame_stage', ['none', 'sales', 'verification', 'packing', 'dispatch', 'courier', 'customer'])->default('none');
            $table->string('system_key', 50)->nullable();
            $table->enum('release_mode', ['manual', 'on_restock', 'on_date'])->default('manual');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['reason_type', 'is_active']);
        });

        Schema::create('delivery_charge_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->nullable()->constrained('delivery_zones')->cascadeOnDelete(); // null = any zone
            $table->unsignedInteger('min_weight_g')->default(0);
            $table->unsignedInteger('max_weight_g')->nullable();   // null = no upper limit
            $table->decimal('min_order_total', 12, 2)->default(0); // free-shipping threshold rows use charge 0
            $table->decimal('charge', 12, 2);
            $table->unsignedSmallInteger('priority')->default(100); // lower wins
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 30)->unique(); // bkash, nagad, rocket, bank, cash, card
            $table->string('name', 60);
            $table->boolean('requires_trx_id')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payment_methods', 'delivery_charge_rules', 'status_reasons', 'order_status_transitions', 'order_statuses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
