<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Booked by mistake": the order went back to Call while its parcel still
 * stands at the courier (taken_back_at, cleared when someone presses
 * Deleted). A box that was started or packed has to be opened and the items
 * put back (unpack_needed_at, cleared by the packer's Unpacked button).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('taken_back_at')->nullable();
            $table->timestamp('unpack_needed_at')->nullable()->index();
            $table->foreignId('unpack_packer_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unpack_packer_id');
            $table->dropColumn(['taken_back_at', 'unpack_needed_at']);
        });
    }
};
