<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB_DESIGN 1.2, 1.5 (zones) and 2. The phone number is the customer key. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('system_key', 40)->nullable()->unique(); // inside_dhaka, sub_dhaka, outside_dhaka
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('primary_phone', 15)->unique();          // normalised 01XXXXXXXXX
            $table->string('messenger_psid', 64)->nullable()->index();
            $table->string('whatsapp_number', 15)->nullable();
            $table->enum('risk_level', ['normal', 'watch', 'blocked'])->default('normal');
            $table->string('blocked_reason', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('orders_count')->default(0);     // denormalised, updated by order events
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('returned_count')->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'name']);
            $table->index(['deleted_at', 'risk_level']);
            $table->index(['deleted_at', 'created_at']);
        });

        // Every number of a customer, primary included, so a lookup is one indexed query.
        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 15)->unique();
            $table->string('label', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('address_line', 500);
            $table->string('district', 60)->nullable();
            $table->string('thana', 80)->nullable();
            $table->unsignedInteger('steadfast_thana_id')->nullable();
            $table->foreignId('zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index('customer_id');
        });

        Schema::create('fraud_check_providers', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 50)->unique();   // steadfast, pathao, redx, internal
            $table->string('name', 100);
            $table->string('driver_class', 200);
            $table->text('credentials')->nullable();      // encrypted
            $table->unsignedInteger('cache_hours')->default(24);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Append-only: every check is kept for history.
        Schema::create('customer_fraud_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('fraud_check_providers');
            $table->string('phone', 15);
            $table->unsignedInteger('total_parcels')->nullable();
            $table->unsignedInteger('delivered')->nullable();
            $table->unsignedInteger('cancelled')->nullable();
            $table->decimal('success_rate', 5, 2)->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('checked_at');
            $table->index(['customer_id', 'provider_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        foreach (['customer_fraud_checks', 'fraud_check_providers', 'customer_addresses', 'customer_phones', 'customers', 'delivery_zones'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
