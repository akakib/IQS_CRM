<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a call's recording or screenshot sits in Google Drive, once it has moved there from the server. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_calls', function (Blueprint $table) {
            $table->string('proof_drive_id', 128)->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('customer_calls', function (Blueprint $table) {
            $table->dropColumn('proof_drive_id');
        });
    }
};
