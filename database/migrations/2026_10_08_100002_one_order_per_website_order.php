<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last guard against a website order coming in twice: the database
 * itself refuses a second order with the same channel and website id.
 * (Orders without a website id keep NULL, which never collides.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['channel', 'external_ref']);
            $table->dropIndex(['channel', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['channel', 'external_ref']);
            $table->dropUnique(['channel', 'external_ref']);
        });
    }
};
