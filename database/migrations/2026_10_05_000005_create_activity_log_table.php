<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: rows are never updated or deleted.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // null = system
            $table->string('action', 60);                 // 'user.updated', 'role.permissions_changed'
            $table->string('subject_type', 60)->nullable(); // 'user', 'role', 'location'
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'id']);
            $table->index(['actor_id', 'id']);
            $table->index(['action', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
