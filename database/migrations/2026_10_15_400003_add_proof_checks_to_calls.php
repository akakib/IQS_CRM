<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was picked as a call's proof: its name on the phone, size and fingerprint (the same file cannot
 * prove two calls), and whether it was from an earlier day. Rider calls get uploaded recordings too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_calls', function (Blueprint $table) {
            $table->string('proof_name', 190)->nullable()->after('proof_drive_id');
            $table->unsignedBigInteger('proof_size')->nullable()->after('proof_name');
            $table->char('proof_hash', 40)->nullable()->index()->after('proof_size');
            $table->boolean('proof_old')->default(false)->after('proof_hash');
        });
        Schema::table('rider_calls', function (Blueprint $table) {
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_drive_id', 128)->nullable();
            $table->string('proof_name', 190)->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->char('proof_hash', 40)->nullable()->index();
            $table->boolean('proof_old')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customer_calls', function (Blueprint $table) {
            $table->dropIndex(['proof_hash']);
            $table->dropColumn(['proof_name', 'proof_size', 'proof_hash', 'proof_old']);
        });
        Schema::table('rider_calls', function (Blueprint $table) {
            $table->dropIndex(['proof_hash']);
            $table->dropColumn(['proof_path', 'proof_drive_id', 'proof_name', 'proof_size', 'proof_hash', 'proof_old']);
        });
    }
};
