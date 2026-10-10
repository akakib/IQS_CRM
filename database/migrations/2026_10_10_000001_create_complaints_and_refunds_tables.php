<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complaints (with photos and a stage to blame), their append-only timeline,
 * and refunds that need a second person's approval before money moves.
 * Complaint categories and refund reasons live in status_reasons
 * (reason_type = complaint / refund) so the admin edits them in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE status_reasons MODIFY reason_type ENUM('status','amendment','return','cancel','hold','refund','reassign','break','complaint') NOT NULL");
        }

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 150);
            $table->string('customer_phone', 15);
            $table->foreignId('category_id')->constrained('status_reasons');
            $table->enum('source', ['phone', 'messenger', 'whatsapp', 'website', 'rider', 'other'])->default('phone');
            $table->text('description');
            // Copied from the category when opened; the resolver can correct it.
            $table->enum('blame_stage', ['none', 'sales', 'verification', 'packing', 'dispatch', 'courier', 'customer'])->default('none');
            $table->foreignId('blamed_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->enum('resolution', ['solved', 'refunded', 'replacement', 'rejected'])->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'sla_due_at']);
            $table->index(['assigned_to', 'status']);
            $table->index('order_id');
            $table->index('customer_id');
            $table->index('created_at');
        });

        Schema::create('complaint_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('original_name', 150)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('complaint_id');
        });

        // Who did what on a complaint: never edited, never deleted.
        Schema::create('complaint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30); // opened, note, assigned, resolved, reopened, escalated, photo_added, refund_*
            $table->text('body')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['complaint_id', 'id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('complaint_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('method_id')->constrained('payment_methods');
            $table->string('recipient_number', 15)->nullable();
            $table->foreignId('reason_id')->nullable()->constrained('status_reasons')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id', 100)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('order_payments')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index('order_id');
            $table->index('complaint_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        foreach (['refunds', 'complaint_events', 'complaint_photos', 'complaints'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
