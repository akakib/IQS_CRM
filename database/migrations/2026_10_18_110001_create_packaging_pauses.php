<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pause packaging (managers): booked orders keep coming, the packers' list stops growing until Resume. One open row = paused. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at');
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resumed_at')->nullable();
            $table->unsignedInteger('held')->default(0); // orders that waited, counted at Resume
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_pauses');
    }
};
