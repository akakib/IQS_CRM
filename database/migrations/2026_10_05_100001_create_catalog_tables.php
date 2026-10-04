<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog: this system is the single source of truth for products, prices,
 * availability, descriptions and SEO. WooCommerce only receives them.
 * Money DECIMAL(12,2), quantities DECIMAL(12,3) (loose goods sell by gram).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 170)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('external_id', 50)->nullable()->index(); // WooCommerce term id
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('focus_keyword', 150)->nullable();
            $table->enum('base_unit', ['pcs', 'g'])->default('pcs');
            $table->unsignedTinyInteger('tracking_level')->default(1); // 1 counts, 2 per-box barcodes (Step 7)
            $table->enum('status', ['active', 'draft', 'archived'])->default('active');
            $table->string('image_url', 500)->nullable();
            $table->json('gallery')->nullable();
            $table->string('tags', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'status', 'name']);
            $table->index(['category_id', 'deleted_at']);
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 30)->unique(); // online | shop | b2b
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 60)->unique();
            $table->string('barcode', 60)->nullable()->unique();
            $table->string('name', 150);                         // "500 g", "5 kg box", "Default"
            $table->enum('unit', ['pcs', 'g', 'box'])->default('pcs');
            $table->decimal('pack_qty', 12, 3)->default(1);      // grams or pieces in one unit sold
            $table->unsignedInteger('weight_g')->nullable();     // shipping weight (delivery charge)
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('search_text', 600)->default('');     // lower(product + variant + sku) for fast search

            // DB_DESIGN 4A: availability before the stock module exists.
            $table->enum('availability_status', ['in_stock', 'backorder', 'out_of_stock'])->default('in_stock');
            $table->decimal('backorder_limit_qty', 12, 3)->nullable();
            $table->decimal('backorder_taken_qty', 12, 3)->default(0);
            $table->enum('availability_source', ['manual', 'auto'])->nullable();
            $table->foreignId('oos_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('oos_marked_at')->nullable();
            $table->date('expected_restock_date')->nullable();
            $table->timestamp('oos_review_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'deleted_at']);
            $table->index(['availability_status', 'deleted_at']);
        });

        Schema::create('variant_prices', function (Blueprint $table) {
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->decimal('regular_price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();   // the discounted price
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['variant_id', 'price_list_id']);
        });

        // Append-only.
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete(); // null = cost price
            $table->enum('field', ['regular', 'sale', 'cost']);
            $table->decimal('old_value', 12, 2)->nullable();
            $table->decimal('new_value', 12, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('source', ['manual', 'import', 'bulk'])->default('manual');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['variant_id', 'id']);
        });

        // Append-only.
        Schema::create('availability_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->enum('source', ['manual', 'auto'])->default('manual');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['variant_id', 'id']);
        });

        Schema::create('channel_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->enum('channel', ['woocommerce', 'shopify']);
            $table->string('external_product_id', 50);
            $table->string('external_variant_id', 50)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('last_sync_status', ['ok', 'failed'])->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_product_id', 'external_variant_id'], 'channel_link_unique');
            $table->index('variant_id');
        });

        // Queued outbound updates (IQS -> website).
        Schema::create('channel_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->enum('channel', ['woocommerce', 'shopify']);
            $table->json('fields');                       // ['price','sale_price','stock_status','content',...]
            $table->json('payload');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        foreach (['channel_sync_jobs', 'channel_product_links', 'availability_events', 'price_history',
            'variant_prices', 'product_variants', 'price_lists', 'products', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
