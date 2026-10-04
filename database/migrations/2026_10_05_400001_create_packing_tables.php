<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 4: batches + pick lists, packer stock-issue reports, handover sessions and scans. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('shelf_code', 30)->nullable()->after('barcode'); // pick lists are sorted by shelf
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_no', 30)->unique();
            $table->date('pickup_date');
            $table->enum('status', ['released', 'picked', 'done'])->default('released');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('telegram_message_id', 40)->nullable();
            $table->timestamps();
            $table->index(['pickup_date', 'status']);
        });

        // Packer could not find an item: a report to Admin, never a status change by the packer.
        Schema::create('stock_issue_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->enum('status', ['open', 'marked_out_of_stock', 'marked_pre_order', 'dismissed'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'id']);
        });

        // Append-only: what happened when an item became available again (FIFO release).
        Schema::create('restock_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->decimal('qty_arrived', 12, 3)->nullable();
            $table->json('released_order_ids');
            $table->decimal('remaining_waiting_qty', 12, 3)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('stock_issue_flag')->default(false)->after('is_duplicate_flag'); // skipped in packing until Admin decides
        });

        Schema::create('handover_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 30)->default('steadfast');
            $table->date('pickup_date');
            $table->string('rider_name', 100)->nullable();
            $table->string('rider_phone', 15)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['pickup_date', 'closed_at']);
        });

        // Every scan at packing or handover, good or bad (bad scans are evidence too).
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('station', ['packing', 'handover']);
            $table->foreignId('handover_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 80);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('label_id')->nullable()->constrained('shipment_labels')->nullOnDelete();
            $table->enum('result', ['ok', 'repack_done', 'relabel_done', 'duplicate', 'blocked', 'unknown']);
            $table->string('message', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['handover_session_id', 'order_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
        Schema::dropIfExists('handover_sessions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('stock_issue_flag'));
        Schema::dropIfExists('restock_releases');
        Schema::dropIfExists('stock_issue_reports');
        Schema::dropIfExists('batches');
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('shelf_code'));
    }
};
