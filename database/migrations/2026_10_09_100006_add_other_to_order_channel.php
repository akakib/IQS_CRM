<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An order can come from somewhere that is none of the listed channels: Other. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('channel', ['web', 'messenger', 'whatsapp', 'phone', 'b2b', 'other'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('channel', ['web', 'messenger', 'whatsapp', 'phone', 'b2b'])->change();
        });
    }
};
