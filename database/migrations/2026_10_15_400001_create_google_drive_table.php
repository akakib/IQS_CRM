<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The one Google Drive the business connects (call recordings go there). Secrets are encrypted by the model. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_drive', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 255)->nullable();
            $table->text('client_secret')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('account_email', 190)->nullable();
            $table->json('folders')->nullable(); // the folders IQS made: root, records, screenshots
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tested_at')->nullable();
            $table->boolean('test_ok')->nullable();
            $table->string('test_message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive');
    }
};
