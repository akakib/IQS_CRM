<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The app font is Figtree. Installs still on the old default move over; a font an admin picked on purpose is left alone. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'appearance.font')->where('value', 'Instrument Sans')->update(['value' => 'Figtree']);
        \Illuminate\Support\Facades\Cache::forget('settings:all'); // settings are cached forever
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'appearance.font')->where('value', 'Figtree')->update(['value' => 'Instrument Sans']);
    }
};
