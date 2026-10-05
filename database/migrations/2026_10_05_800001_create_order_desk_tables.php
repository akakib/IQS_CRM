<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order management desk: take-next queue, 10-minute action timer, No response
 * returns, background booking, shared packing queue, breaks, working days and
 * KPI targets. Timers are plain timestamps on the order, so "is it due?" is a
 * WHERE clause and needs no job; a cron sweep only catches what nobody touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('queue_since')->nullable();            // waiting unassigned since (auto-assign clock)
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('action_due_at')->nullable()->index(); // 10-minute timer; null = not running
            $table->timestamp('next_call_at')->nullable()->index();  // No response: when it returns to the Call tab
            $table->unsignedTinyInteger('no_response_count')->default(0);
            $table->boolean('had_setback')->default(false);          // had No response or Hold (a later delivery = "saved")
            $table->enum('booking_state', ['none', 'queued', 'failed'])->default('none')->index();
            $table->unsignedTinyInteger('booking_attempts')->default(0);
            $table->string('booking_error', 255)->nullable();
            $table->timestamp('packing_sent_at')->nullable();        // reached the packing queue (CN exists)
            $table->foreignId('packer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('packing_started_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->index(['packer_id', 'status_id']);
        });

        Schema::table('order_assignments', function (Blueprint $table) {
            $table->string('ended_reason', 12)->nullable();          // finished | timeout | reassigned | break
            $table->index(['user_id', 'ended_reason', 'ended_at']);
        });

        Schema::table('status_reasons', function (Blueprint $table) {
            $table->boolean('counts_as_break')->default(true);       // break reasons: false = away on work (not in the limit)
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('current_break_id')->nullable(); // open break (overlay shows while set)
        });

        // Weekly office days per person. No rows for someone = the global default (Settings).
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');                  // 0 = Sunday … 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->unique(['user_id', 'weekday']);
        });

        Schema::create('staff_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('reason_id')->nullable()->constrained('status_reasons')->nullOnDelete();
            $table->boolean('counts_as_break')->default(true);       // snapshot of the reason's flag
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->boolean('auto_closed')->default(false);          // never pressed Start work
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correction_note', 255)->nullable();
            $table->index(['user_id', 'started_at']);
            $table->index('ended_at');
        });

        // One row per person per day they used the system (attendance source).
        Schema::create('work_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->date('work_date');
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_extra')->default(false);             // worked on an off day
            $table->foreignId('extra_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('extra_approved_at')->nullable();
            $table->unique(['user_id', 'work_date']);
            $table->index(['work_date', 'is_extra']);
        });

        Schema::create('packer_shifts', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['work_date', 'user_id']);
        });

        // Monthly targets. user_id NULL = default for everyone.
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('metric', 30);                            // delivered_count | delivery_rate
            $table->decimal('target', 10, 2);
            $table->timestamps();
            $table->unique(['user_id', 'metric']);
        });

        // Databases built before this change: widen the enums and fix seeded config.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE status_reasons MODIFY reason_type ENUM('status','amendment','return','cancel','hold','refund','reassign','break') NOT NULL");
            DB::statement("ALTER TABLE order_assignments MODIFY how ENUM('claimed','created','assigned','reassigned','auto') NOT NULL");
        }

        // Moderators check manual-review orders themselves now.
        $ids = DB::table('order_statuses')->whereNotNull('system_key')->pluck('id', 'system_key');
        if (isset($ids['new'], $ids['record_verified'])) {
            DB::table('order_status_transitions')->where('from_status_id', $ids['new'])->where('to_status_id', $ids['record_verified'])
                ->where('permission_key', 'orders.approve')->update(['permission_key' => 'orders.edit']);
        }

        // Waiting orders start their auto-assign clock now.
        DB::table('orders')->whereNull('moderator_id')->update(['queue_since' => now()]);
        DB::table('orders')->whereNotNull('moderator_id')->whereNull('assigned_at')->update(['assigned_at' => now()]);

        // Scoring is outcome-based now: nothing for taking or confirming, risky bonus removed.
        if (Schema::hasTable('point_rules')) {
            DB::table('point_rules')->whereIn('name', [
                'Took an order', 'Confirmed an order (counts only if delivered)',
                'Risky order sent and delivered', 'Risky order sent and refused by the customer',
            ])->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('point_rules')->where('name', 'Cancelled by a sales mistake')->where('points', -2)->update(['points' => -1, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['kpi_targets', 'packer_shifts', 'work_days', 'staff_breaks', 'work_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['last_seen_at', 'current_break_id']));
        Schema::table('status_reasons', fn (Blueprint $table) => $table->dropColumn('counts_as_break'));
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'ended_reason', 'ended_at']);
            $table->dropColumn('ended_reason');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packer_id');
            $table->dropIndex(['action_due_at']);
            $table->dropIndex(['next_call_at']);
            $table->dropIndex(['booking_state']);
            $table->dropColumn(['queue_since', 'assigned_at', 'action_due_at', 'next_call_at', 'no_response_count', 'had_setback',
                'booking_state', 'booking_attempts', 'booking_error', 'packing_sent_at', 'packing_started_at', 'packed_at']);
        });
    }
};
