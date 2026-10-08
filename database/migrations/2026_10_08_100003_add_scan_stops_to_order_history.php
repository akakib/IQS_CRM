<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order history learns a new kind of entry: a scan that refused the order
 * (handover or packaging). Stops already in the scan log are written into
 * the history once, at their own time, without sending notices again.
 */
return new class extends Migration
{
    private const TYPES = ['manual', 'system', 'call', 'chat', 'rider', 'amendment', 'payment', 'status', 'courier', 'verification', 'assignment'];

    public function up(): void
    {
        Schema::table('order_notes', function (Blueprint $table) {
            $table->enum('note_type', [...self::TYPES, 'scan'])->change();
        });

        $scans = app(\App\Services\Packaging\ScanService::class);
        DB::table('scan_logs')->where('result', 'blocked')->whereNotNull('order_id')->orderBy('id')
            ->each(fn ($log) => $scans->noteStop($log->station, $log->handover_session_id, (int) $log->order_id, (string) $log->message, $log->user_id, Carbon::parse($log->created_at), false));
    }

    public function down(): void
    {
        DB::table('order_notes')->where('note_type', 'scan')->delete();
        Schema::table('order_notes', function (Blueprint $table) {
            $table->enum('note_type', self::TYPES)->change();
        });
    }
};
