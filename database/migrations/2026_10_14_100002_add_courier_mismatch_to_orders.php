<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The courier reported a COD different from ours before delivery (someone
 * changed the parcel in the courier's panel instead of editing the order):
 * the handover is blocked until a manager checks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('courier_mismatch_at')->nullable();
            $table->decimal('courier_mismatch_cod', 12, 2)->nullable(); // what the courier said
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_mismatch_at', 'courier_mismatch_cod']);
        });
    }
};
