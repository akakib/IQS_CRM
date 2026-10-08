<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desk rules per person and a log of the time each order took.
 *
 * - users.desk_*: blank = the shop-wide setting; a number = this person's own.
 * - orders.timer_overran_at: the time limit passed; the order stays with the
 *   person (logged and scored), it only goes back after a long idle.
 * - order_work_logs: one row per timed piece of work (start, limit, end).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('desk_limit')->nullable()->after('voice_name');
            $table->unsignedSmallInteger('desk_timer_minutes')->nullable()->after('desk_limit');
            $table->unsignedSmallInteger('desk_extend_minutes')->nullable()->after('desk_timer_minutes');
            $table->unsignedSmallInteger('desk_extend_daily_limit')->nullable()->after('desk_extend_minutes');
            $table->boolean('desk_voice')->nullable()->after('desk_extend_daily_limit');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('timer_overran_at')->nullable()->after('action_due_at')->index();
        });

        Schema::create('order_work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('started_at');
            $table->unsignedSmallInteger('limit_minutes');
            $table->unsignedSmallInteger('extended_minutes')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->unsignedInteger('seconds')->nullable();
            $table->boolean('overran')->default(false);
            $table->string('outcome', 30)->nullable(); // the status it moved to, or break / idle / reassigned
            $table->index(['user_id', 'started_at']);
            $table->index(['order_id', 'done_at']);
        });

        // Finishing in time is a rule like any other: 0 = nothing happens, + adds, - takes away.
        if (! DB::table('point_rules')->where('trigger_key', 'timer_beaten')->exists()) {
            DB::table('point_rules')->insert([
                'trigger_key' => 'timer_beaten', 'name' => 'Finished an order within the time limit', 'points' => 0,
                'recipient' => 'actor', 'settle_on' => 'immediate', 'requires_delivery' => false, 'is_active' => true,
                'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Measure first: going over time costs nothing until the admin sets a number (only the untouched seed values change).
        DB::table('point_rules')->where('trigger_key', 'timer_missed')->where('points', -1)->update(['points' => 0, 'updated_at' => now()]);
        DB::table('point_rules')->where('trigger_key', 'timer_missed')->where('name', 'Action timer missed')
            ->update(['name' => 'Went over the time limit on an order']);
        DB::table('point_rules')->where('trigger_key', 'timer_missed')->where('name', 'Action timer missed again (more than 3 today)')
            ->update(['name' => 'Went over the time limit again (more than 3 today)']);
    }

    public function down(): void
    {
        DB::table('point_rules')->where('trigger_key', 'timer_beaten')->delete();
        Schema::dropIfExists('order_work_logs');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('timer_overran_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['desk_limit', 'desk_timer_minutes', 'desk_extend_minutes', 'desk_extend_daily_limit', 'desk_voice']));
    }
};
