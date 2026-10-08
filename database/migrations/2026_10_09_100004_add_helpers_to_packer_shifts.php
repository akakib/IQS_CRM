<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers working with a packer on a day: names only (they have no login),
 * so the Packers report can show packs per head, not just per packer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packer_shifts', function (Blueprint $table) {
            $table->string('helpers', 255)->nullable()->after('user_id');
            $table->unsignedTinyInteger('helper_count')->default(0)->after('helpers');
        });
    }

    public function down(): void
    {
        Schema::table('packer_shifts', fn (Blueprint $table) => $table->dropColumn(['helpers', 'helper_count']));
    }
};
