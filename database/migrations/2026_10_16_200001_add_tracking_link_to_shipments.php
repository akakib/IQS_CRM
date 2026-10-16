<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The courier's public tracking page (Steadfast: https://steadfast.com.bd/tl/...), given with the booking.
 * Its own column so pages show and copy it without reading the whole booking reply; the parcels already
 * booked get it from the reply that was kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('tracking_link', 255)->nullable()->after('tracking_code');
        });

        DB::table('shipments')->whereNotNull('raw_booking')->orderBy('id')->select(['id', 'raw_booking'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw_booking, true);
                    $link = is_array($raw) ? ($raw['consignment']['tracking_link'] ?? $raw['tracking_link'] ?? null) : null;
                    if (is_string($link) && str_starts_with($link, 'https://')) {
                        DB::table('shipments')->where('id', $row->id)->update(['tracking_link' => mb_substr($link, 0, 255)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('tracking_link');
        });
    }
};
