<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A courier message that could not be applied is tried again (desk:tick) up to 5 times. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_events', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('error');
            $table->index(['processed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('courier_events', function (Blueprint $table) {
            $table->dropIndex(['processed_at', 'created_at']);
            $table->dropColumn('attempts');
        });
    }
};
