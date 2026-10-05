<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier riders who collect parcels. They used to be read back from old
 * handovers; their own table keeps them when handover history is cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('phone', 15)->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamps();
        });

        // Riders already typed into handovers (the newest phone wins).
        $seen = [];
        foreach (DB::table('handover_sessions')->whereNotNull('rider_name')->orderByDesc('id')->get(['rider_name', 'rider_phone', 'started_at']) as $s) {
            $name = trim($s->rider_name);
            if ($name === '' || isset($seen[mb_strtolower($name)])) {
                continue;
            }
            $seen[mb_strtolower($name)] = true;
            DB::table('riders')->insert(['name' => $name, 'phone' => $s->rider_phone, 'last_used_at' => $s->started_at, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('riders');
    }
};
