<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB_DESIGN 1.3, 1.4 and 4: verification rules, tracking events, shipments, labels, courier events, delivery issues. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->unsignedSmallInteger('priority')->default(100);  // lower runs first
            $table->enum('outcome', ['record_verified', 'record_verified_and_confirmed', 'manual_review', 'hold_for_advance']);
            $table->enum('applies_to_channel', ['all', 'web', 'messenger', 'whatsapp', 'phone'])->default('all');
            $table->boolean('stop_on_match')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // All conditions of a rule must pass (AND). No conditions = always matches (fallback).
        Schema::create('verification_rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('verification_rules')->cascadeOnDelete();
            $table->string('field', 40);
            $table->foreignId('provider_id')->nullable()->constrained('fraud_check_providers')->nullOnDelete();
            $table->enum('operator', ['>=', '<=', '=', '!=', '>', '<']);
            $table->string('value', 50);
        });

        // Append-only: explains every automatic decision months later.
        Schema::create('verification_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matched_rule_id')->nullable()->constrained('verification_rules')->nullOnDelete();
            $table->string('outcome', 50);
            $table->json('inputs_snapshot');
            $table->enum('ran_by', ['system', 'user']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });

        Schema::create('tracking_event_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('platform', ['meta_capi', 'ga4', 'tiktok']);
            $table->string('event_name', 50);
            $table->enum('fire_on', ['order_created', 'record_verified', 'confirmed', 'delivered']);
            $table->enum('value_basis', ['order_total', 'product_subtotal', 'delivered_amount']);
            $table->json('channels');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['platform', 'event_name']);
        });

        // One row per attempt; a 'sent' row per order+platform+event means it never fires again.
        Schema::create('tracking_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('event_name', 50);
            $table->string('event_id', 100);
            $table->timestamp('event_time');
            $table->decimal('value', 12, 2);
            $table->enum('status', ['queued', 'sent', 'failed', 'skipped']);
            $table->json('request_payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'platform', 'event_name', 'status'], 'tracking_once');
        });

        // An order can have several (rebook, exchange); exactly one is active.
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('courier', 30);
            $table->string('consignment_id', 50)->nullable();
            $table->string('tracking_code', 50)->nullable();
            $table->decimal('cod_amount', 12, 2);
            $table->string('courier_status', 50)->nullable();
            $table->decimal('delivery_charge', 12, 2)->nullable();
            $table->decimal('collected_amount', 12, 2)->nullable();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('final_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('raw_booking')->nullable();
            $table->timestamps();
            $table->unique(['courier', 'consignment_id']);
            $table->index(['order_id', 'is_active']);
        });

        Schema::create('shipment_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order_version');
            $table->string('barcode', 60)->unique();
            $table->decimal('cod_on_label', 12, 2);
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 150)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'voided_at']);
        });

        // Append-only raw courier webhooks / pulls, stored before processing.
        Schema::create('courier_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier', 30);
            $table->string('consignment_id', 50)->nullable()->index();
            $table->string('notification_type', 40)->nullable();
            $table->json('payload');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('processed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('delivery_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('issue_type', ['no_answer', 'partial', 'cancel', 'exchange', 'address', 'hold', 'other']);
            $table->string('rider_phone', 15)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete(); // null = courier webhook
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->enum('resolution', ['delivered', 'rescheduled', 'partial', 'returned', 'exchange_created', 'solved'])->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'resolved_at']);
            $table->index(['resolved_at', 'sla_due_at']);
        });
    }

    public function down(): void
    {
        foreach (['delivery_issues', 'courier_events', 'shipment_labels', 'shipments', 'tracking_event_logs', 'tracking_event_settings',
            'verification_runs', 'verification_rule_conditions', 'verification_rules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
