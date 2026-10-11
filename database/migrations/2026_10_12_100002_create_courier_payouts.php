<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COD from the courier: each payment statement uploaded (courier_payouts),
 * and on every parcel what the statement said it paid. A delivered parcel
 * with no payout is money still owed; a payout that differs is a mismatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 30)->default('steadfast');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('file_name', 190);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('mismatched')->default(0);
            $table->unsignedInteger('unknown')->default(0);
            $table->decimal('total_collected', 14, 2)->default(0);
            $table->decimal('total_charge', 14, 2)->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('payout_id')->nullable()->after('collected_amount')->constrained('courier_payouts')->nullOnDelete();
            $table->decimal('payout_collected', 12, 2)->nullable()->after('payout_id');
            $table->decimal('payout_charge', 12, 2)->nullable()->after('payout_collected');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
            $table->dropColumn(['payout_collected', 'payout_charge']);
        });
        Schema::dropIfExists('courier_payouts');
    }
};
