<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website's REST API keys, typed in Settings (encrypted) instead of the
 * server .env, so products can be pulled from the website. product_imports
 * also records API pulls: source "api", cursor = the next page to fetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('url', 255);
            $table->text('consumer_key');            // encrypted
            $table->text('consumer_secret');         // encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_result', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('product_imports', function (Blueprint $table) {
            $table->string('source', 10)->default('csv')->after('user_id');
            $table->unsignedInteger('cursor')->default(0)->after('offset');
        });
    }

    public function down(): void
    {
        Schema::table('product_imports', function (Blueprint $table) {
            $table->dropColumn(['source', 'cursor']);
        });
        Schema::dropIfExists('website_accounts');
    }
};
