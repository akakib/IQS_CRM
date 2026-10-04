<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB_DESIGN 4B. The per-recipient table is `app_notifications` (not
 * `notifications`) so it never collides with Laravel's own database
 * notification channel, which expects a different shape under that name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catalogue of events, synced from config/notifications.php.
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 60)->unique();
            $table->string('name', 150);
            $table->enum('default_priority', ['info', 'normal', 'urgent'])->default('normal');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The alert matrix: who gets which event, on which channel.
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->constrained('notification_types')->cascadeOnDelete();
            $table->enum('target', ['role', 'user', 'order_owner', 'actor_manager']);
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('channel_in_app')->default(true);
            $table->boolean('channel_telegram')->default(false);
            $table->boolean('channel_sms')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type_id', 'is_active']);
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('notification_types');
            $table->enum('priority', ['info', 'normal', 'urgent'])->default('normal');
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('group_key', 120)->nullable();
            $table->unsignedInteger('group_count')->default(1);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acted_at')->nullable(); // urgent stays highlighted until the task is done
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'id']);
            $table->index(['user_id', 'group_key', 'read_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Append-only log per outside channel.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $table->enum('channel', ['telegram', 'sms']);
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('user_notification_prefs', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('notification_types')->cascadeOnDelete();
            $table->timestamp('mute_until')->nullable(); // urgent types cannot be muted
            $table->json('quiet_hours')->nullable();
            $table->primary(['user_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_prefs');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_types');
    }
};
