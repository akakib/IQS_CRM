<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB_DESIGN 3 and 4 (orders, versions, amendments, notes, events, payments, shipments, inbox). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->nullable()->unique();     // also the Steadfast invoice
            $table->enum('channel', ['web', 'messenger', 'whatsapp', 'phone', 'b2b']);
            $table->string('external_ref', 100)->nullable();          // WooCommerce order id
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('status_id')->constrained('order_statuses');
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('packed_version')->nullable();
            $table->unsignedInteger('label_version')->nullable();
            $table->boolean('edited_after_pack')->default(false);

            // Delivery snapshot (copied from the address; changed only through amendments).
            $table->string('ship_name', 150);
            $table->string('ship_phone', 15);
            $table->string('ship_alt_phone', 15)->nullable();
            $table->string('ship_address', 500);
            $table->string('ship_district', 60)->nullable();
            $table->string('ship_thana', 80)->nullable();
            $table->foreignId('zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();

            // Money: always recomputed from items + payments, never typed.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('advance_verified', 12, 2)->default(0);
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('refund_due', 12, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial_advance', 'fully_prepaid', 'cod_collected', 'refund_due', 'refunded'])->default('unpaid');
            $table->unsignedInteger('total_weight_g')->default(0);

            // Attribution
            $table->string('source_campaign', 150)->nullable();
            $table->json('utm')->nullable();
            $table->string('fbp', 255)->nullable();
            $table->string('fbc', 255)->nullable();

            // Fulfilment and verification (FKs to later tables added when those exist)
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->unsignedBigInteger('active_shipment_id')->nullable()->index();
            $table->unsignedBigInteger('verification_rule_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->date('pickup_date')->nullable();
            $table->date('hold_expected_date')->nullable();
            $table->foreignId('hold_reason_id')->nullable()->constrained('status_reasons')->nullOnDelete();
            $table->boolean('is_duplicate_flag')->default(false);
            $table->string('customer_note', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(0);       // optimistic locking
            $table->timestamps();

            $table->index(['status_id', 'id']);
            $table->index(['moderator_id', 'status_id']);
            $table->index(['customer_id', 'id']);
            $table->index('created_at');
            $table->index(['channel', 'external_ref']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->string('name_snapshot', 255);
            $table->string('sku_snapshot', 60);
            $table->enum('unit', ['pcs', 'g', 'box']);
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_discount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->decimal('cost_price_snapshot', 12, 2)->nullable(); // frozen at confirmation (P&L)
            $table->unsignedInteger('weight_g')->default(0);
            $table->index('order_id');
            $table->index('variant_id');
        });

        // Append-only full snapshot after every change.
        Schema::create('order_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('items_snapshot');
            $table->json('totals_snapshot');
            $table->json('shipping_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['order_id', 'version_no']);
        });

        Schema::create('order_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('from_version');
            $table->unsignedInteger('to_version')->nullable();
            $table->foreignId('status_at_time_id')->constrained('order_statuses');
            $table->foreignId('reason_id')->constrained('status_reasons');
            $table->enum('edit_class', ['content', 'label', 'internal']);
            $table->json('changes');
            $table->json('proposed');                  // items + shipping to apply (for approval)
            $table->decimal('amount_diff', 12, 2)->default(0);
            $table->enum('approval_status', ['not_required', 'pending', 'approved', 'rejected'])->default('not_required');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->enum('courier_action', ['none', 'update_cod', 'rebook', 'reprint_label'])->default('none');
            $table->timestamp('courier_action_done_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'id']);
            $table->index('approval_status');
        });

        // Append-only timeline: status changes, edits, calls, payments, rider notes.
        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('note_type', ['manual', 'system', 'call', 'chat', 'rider', 'amendment', 'payment', 'status', 'courier', 'verification', 'assignment']);
            $table->text('body');
            $table->json('meta')->nullable();
            $table->foreignId('status_at_time_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_internal')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });

        // Append-only status transitions: the ONLY source for KPIs and points.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('order_statuses');
            $table->foreignId('to_status_id')->constrained('order_statuses');
            $table->enum('source', ['user', 'rule', 'webhook', 'scan', 'system']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reason_id')->nullable()->constrained('status_reasons')->nullOnDelete();
            $table->unsignedInteger('seconds_since_previous')->nullable();
            $table->boolean('is_reverted')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index('order_id');
            $table->index(['user_id', 'created_at']);
            $table->index(['to_status_id', 'created_at']);
        });

        // Assignment history.
        Schema::create('order_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->enum('role', ['moderator', 'temporary']);
            $table->enum('how', ['claimed', 'created', 'assigned', 'reassigned']);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reason_id')->nullable()->constrained('status_reasons')->nullOnDelete();
            $table->string('reason', 150)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->index(['order_id', 'ended_at']);
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('payment_type', ['advance', 'cod', 'refund', 'adjustment']);
            $table->foreignId('method_id')->constrained('payment_methods');
            $table->decimal('amount', 12, 2);
            $table->string('transaction_id', 100)->nullable();
            $table->string('sender_number', 15)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->enum('status', ['pending_verification', 'verified', 'rejected'])->default('pending_verification');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['method_id', 'transaction_id']); // the same bKash TrxID cannot be used twice
            $table->index('order_id');
        });

        // Idempotent intake of webhooks (WooCommerce may send the same one twice).
        Schema::create('integration_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('external_id', 100);
            $table->string('topic', 60)->nullable();
            $table->json('payload');
            $table->enum('status', ['received', 'processed', 'failed', 'ignored'])->default('received');
            $table->text('error')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        foreach (['integration_inbox', 'order_payments', 'order_assignments', 'order_events', 'order_notes', 'order_amendments', 'order_versions', 'order_items', 'orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
