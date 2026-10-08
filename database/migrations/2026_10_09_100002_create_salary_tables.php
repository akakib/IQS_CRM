<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay for the monthly scorecard: a fixed monthly salary (a new row from the
 * month it changes, so older months keep the amount they had) and a bonus
 * per month, decided at month end from points or targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('from_month'); // first day of the month it applies from
            $table->decimal('monthly_salary', 12, 2);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'from_month']);
        });

        Schema::create('staff_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // first day of the month
            $table->decimal('amount', 12, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_bonuses');
        Schema::dropIfExists('staff_salaries');
    }
};
