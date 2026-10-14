<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What "Possible duplicate" matched: which order, and whether items and address are the same. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('duplicate_of_no', 20)->nullable()->after('is_duplicate_flag');
            $table->string('duplicate_items', 10)->nullable()->after('duplicate_of_no');   // same | some | other
            $table->string('duplicate_address', 10)->nullable()->after('duplicate_items'); // same | other
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['duplicate_of_no', 'duplicate_items', 'duplicate_address']);
        });
    }
};
