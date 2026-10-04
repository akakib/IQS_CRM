<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Location types become warehouse / shop / virtual (App\Enums\LocationType).
 * Online is a department and sales channel, not a place where stock sits,
 * and all packing happens at the shop (Riajuddin Bazar).
 *
 * The column becomes a plain string validated by the PHP enum, so a future
 * type needs no ALTER on the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });

        DB::table('locations')->where('type', 'store')->update(['type' => 'warehouse']);

        // The seeded "Online" row was never a real stock location. Remove it
        // when nothing points at it, otherwise keep it as an inactive virtual one.
        foreach (DB::table('locations')->where('type', 'online')->pluck('id') as $id) {
            if (DB::table('users')->where('work_location_id', $id)->exists()) {
                DB::table('locations')->where('id', $id)->update(['type' => 'virtual', 'is_active' => false]);
            } else {
                DB::table('locations')->where('id', $id)->delete();
            }
        }

        // The generic seeded "Shop" is the real shop.
        if (! DB::table('locations')->where('name', 'Riajuddin Bazar')->exists()) {
            DB::table('locations')->where('name', 'Shop')->where('type', 'shop')
                ->update(['name' => 'Riajuddin Bazar', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('locations')->where('type', 'warehouse')->update(['type' => 'store']);

        Schema::table('locations', function (Blueprint $table) {
            $table->enum('type', ['store', 'shop', 'online', 'virtual'])->change();
        });
    }
};
