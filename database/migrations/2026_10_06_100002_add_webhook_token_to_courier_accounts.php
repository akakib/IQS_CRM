<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The webhook auth token set in the Steadfast panel, so it can be pasted here instead of the server .env. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table) {
            $table->text('webhook_token')->nullable()->after('secret_key'); // encrypted
        });
    }

    public function down(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table) {
            $table->dropColumn('webhook_token');
        });
    }
};
