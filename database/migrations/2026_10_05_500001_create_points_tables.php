<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points (speed + fewer mistakes), separate from KPI (volume). Triggers are
 * fixed in code; every rule (how many points, plus or minus, who, when,
 * conditions) is admin-editable. The ledger is append-only in spirit:
 * entries are never deleted, only finalised or revoked with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_key', 50)->index();
            $table->string('name', 150);
            $table->decimal('points', 8, 2);                         // + or -
            $table->enum('recipient', ['order_moderator', 'actor', 'packer', 'previous_moderator']);
            $table->enum('settle_on', ['immediate', 'order_final'])->default('order_final');
            $table->boolean('requires_delivery')->default(false);   // revoked if the order ends not delivered
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('point_rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('point_rules')->cascadeOnDelete();
            $table->string('field', 40);
            $table->enum('operator', ['>=', '<=', '=', '!=', '>', '<']);
            $table->string('value', 50);
        });

        Schema::create('point_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('point_rules')->nullOnDelete();
            $table->string('trigger_key', 50);
            $table->decimal('points', 8, 2);
            $table->json('rule_snapshot');                           // the rule as it was when awarded
            $table->json('context')->nullable();                     // inputs that matched (seconds, risky, reason…)
            $table->enum('status', ['pending', 'final', 'revoked'])->default('pending');
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_note', 500)->nullable();
            $table->enum('dispute_status', ['open', 'upheld', 'rejected'])->nullable();
            $table->foreignId('dispute_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'rule_id', 'user_id'], 'point_once_per_order_rule_user');
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('integrity_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('flag_type', 50);                         // too_fast_confirm, flip_flop, qa_not_called, manual
            $table->enum('detected_by', ['system', 'qa', 'manager'])->default('system');
            $table->string('details', 500)->nullable();
            $table->enum('status', ['open', 'confirmed', 'dismissed'])->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->enum('result', ['call_verified', 'not_called', 'wrong_info']);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique('order_id');
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['qa_reviews', 'integrity_flags', 'point_ledger', 'point_rule_conditions', 'point_rules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
