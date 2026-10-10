<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Month close: the Scorecard of a finished month is saved as it stood, so a
 * later return, revoked point or edit does not change what the bonus was
 * decided on. users.left_at: when someone was deactivated (salary and target
 * count only the days they were with us).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scorecard_closes', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->json('snapshot');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->useCurrent();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scorecard_closes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }
};
