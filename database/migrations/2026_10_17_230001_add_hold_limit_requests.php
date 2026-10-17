<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds one person may keep at once: their own number (Staff page, or a manager's yes to a request), else the shop
 * setting desk.hold_limit. A request says why more are needed; a manager sets the new number or says no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('desk_hold_limit')->nullable()->after('desk_limit');
        });
        Schema::create('hold_limit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('current_limit');
            $table->string('reason', 300);
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->unsignedTinyInteger('new_limit')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_limit_requests');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('desk_hold_limit'));
    }
};
