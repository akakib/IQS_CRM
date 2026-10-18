<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Packing sheet and label scan: labels printed together share a print_run (the A4 sheet lists the same orders);
 * applied_at = the packer scanned the label on the box. Edits while packing no longer wait for a manager
 * (the packer is told at once what to do instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_labels', function (Blueprint $table) {
            $table->string('print_run', 20)->nullable()->index();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('order_statuses')->whereIn('system_key', ['ready_for_packaging', 'packed', 'ready_for_pickup'])->where('edit_policy', 'approval')
            ->update(['edit_policy' => 'free', 'updated_at' => now()]);
        \App\Models\OrderStatus::forget();
    }

    public function down(): void
    {
        DB::table('order_statuses')->whereIn('system_key', ['ready_for_packaging', 'packed', 'ready_for_pickup'])->update(['edit_policy' => 'approval']);
        Schema::table('shipment_labels', fn (Blueprint $table) => $table->dropConstrainedForeignId('applied_by'));
        Schema::table('shipment_labels', fn (Blueprint $table) => $table->dropColumn(['print_run', 'applied_at']));
    }
};
