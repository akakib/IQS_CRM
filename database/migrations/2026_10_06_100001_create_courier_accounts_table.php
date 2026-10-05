<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier API accounts (Steadfast for now), set and changed by the owner in
 * Settings instead of the server .env. Keys are stored encrypted. One account
 * is the default used for booking; more can be added for later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 30)->default('steadfast');
            $table->string('name', 100);
            $table->text('api_key');                 // encrypted
            $table->text('secret_key');              // encrypted
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_result', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['courier', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_accounts');
    }
};
