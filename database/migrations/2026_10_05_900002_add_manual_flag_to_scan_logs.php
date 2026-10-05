<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Handover can be ticked by hand when scanning is not possible; the log says which way each parcel went. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            $table->boolean('manual')->default(false); // true = ticked by hand, no label scan
        });
    }

    public function down(): void
    {
        Schema::table('scan_logs', fn (Blueprint $table) => $table->dropColumn('manual'));
    }
};
