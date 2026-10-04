<?php

namespace App\Http\Controllers;

use App\Services\Courier\CourierManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/** Owner-only system health: backups, queue, scheduler. */
class HealthController extends Controller
{
    public function __invoke(CourierManager $courier): View
    {
        $backups = collect(File::glob(config('backup.path').'/iqs-*.sql.gz'))->sortDesc()->values();
        $latest = $backups->first();
        $lastRun = Cache::get('schedule:last_run');

        return view('health', [
            'backup' => $latest ? [
                'name' => basename($latest),
                'at' => Carbon::createFromTimestamp(filemtime($latest)),
                'size' => filesize($latest),
            ] : null,
            'backupCount' => $backups->count(),
            'backupError' => Cache::get('backup:last_error'),
            'offServer' => config('backup.copy_to_disk'),
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'oldest' => ($t = DB::table('jobs')->min('created_at')) ? Carbon::createFromTimestamp($t) : null,
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'scheduler' => $lastRun ? Carbon::parse($lastRun) : null,
            'courier' => $courier->resolvedName(),
            'env' => app()->environment(),
        ]);
    }
}
