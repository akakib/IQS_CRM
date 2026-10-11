<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office default week with a start date, like a person's own week:
 * changing the office hours in Settings counts from that day, and the past
 * keeps the hours it had. Written by SettingsService when work.* changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->string('days', 20);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_schedules');
    }
};
