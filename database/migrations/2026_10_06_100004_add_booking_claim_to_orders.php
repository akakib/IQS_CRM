<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Booking in progress": the one request that is sending this order to the
 * courier right now writes its token here first, so two requests can never
 * send the same order. book_after (added earlier) is now the next retry time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('booking_claim', 36)->nullable()->after('book_after');
            $table->timestamp('booking_claimed_at')->nullable()->after('booking_claim');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['booking_claim', 'booking_claimed_at']);
        });
    }
};
