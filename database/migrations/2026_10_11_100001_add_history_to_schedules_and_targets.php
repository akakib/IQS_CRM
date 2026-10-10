<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History, so changing something today does not rewrite the past:
 *  - work_schedules.effective_from: a person's week from that date (weekday 7 = "back to the office
 *    default" from that date). Existing rows count from the beginning.
 *  - kpi_targets.from_month: a target from that month (null = from the beginning; target null = none
 *    from that month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->date('effective_from')->default('2000-01-01');
        });
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unique(['user_id', 'weekday', 'effective_from']); // first, so user_id keeps an index for its foreign key
        });
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'weekday']);
        });

        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->date('from_month')->nullable();
            $table->decimal('target', 10, 2)->nullable()->change();
        });
        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->unique(['user_id', 'metric', 'from_month']);
        });
        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->unique(['user_id', 'metric']);
        });
        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'metric', 'from_month']);
            $table->dropColumn('from_month');
        });
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unique(['user_id', 'weekday']);
        });
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'weekday', 'effective_from']);
            $table->dropColumn('effective_from');
        });
    }
};
