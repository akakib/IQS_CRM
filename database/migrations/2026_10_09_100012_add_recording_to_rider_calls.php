<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A rider call, like a customer call, keeps how long it was and the recording's Drive link. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_calls', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable()->after('note');
            $table->string('recording_url', 500)->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('rider_calls', function (Blueprint $table) {
            $table->dropColumn(['duration_seconds', 'recording_url']);
        });
    }
};
