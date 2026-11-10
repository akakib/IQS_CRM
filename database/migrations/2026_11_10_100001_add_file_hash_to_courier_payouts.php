<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The same payout statement uploaded twice is refused by its content, not only by a typed reference. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->char('file_hash', 40)->nullable()->after('file_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('courier_payouts', function (Blueprint $table) {
            $table->dropIndex(['file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
