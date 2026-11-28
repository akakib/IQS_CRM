<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Today sheet's two ticks (Packed, Label on) lock once given. A packer who ticked by mistake asks here;
 * the owner or a manager approves (the tick goes) or refuses (it stays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_undo_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('mark', ['packed', 'label']);
            $table->string('reason', 300);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'approved', 'refused'])->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_undo_requests');
    }
};
