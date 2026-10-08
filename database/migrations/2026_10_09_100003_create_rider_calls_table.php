<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calls from delivery riders to the hotline: which rider, which parcel,
 * what the rider said, whether it turned out true when the customer was
 * called, and what was done. The Riders report counts these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('consignment_id', 40)->nullable()->index();
            $table->foreignId('rider_id')->nullable()->constrained('riders')->nullOnDelete();
            $table->string('rider_phone', 15)->nullable();
            $table->string('claim', 20);              // what the rider said (no_answer, address, refused, later, cod, other)
            $table->string('verdict', 10)->nullable(); // true, false, unclear: after calling the customer
            $table->string('action', 20)->nullable();  // solved, rescheduled, moderator, cancel, retry
            $table->string('note', 500)->nullable();
            $table->foreignId('delivery_issue_id')->nullable()->constrained('delivery_issues')->nullOnDelete();
            $table->foreignId('handled_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['rider_id', 'created_at']);
            $table->index(['handled_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_calls');
    }
};
