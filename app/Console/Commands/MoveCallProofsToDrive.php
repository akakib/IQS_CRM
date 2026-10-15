<?php

namespace App\Console\Commands;

use App\Models\GoogleDrive;
use App\Services\Drive\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Call recordings and call-log screenshots go from the server to Google Drive:
 * new ones within a minute, older ones a batch at a time. The server copy is
 * removed only after Drive has the file. When Drive is not connected, or fails,
 * files simply wait on the server and are tried again next minute.
 */
class MoveCallProofsToDrive extends Command
{
    protected $signature = 'calls:proofs-to-drive {--limit=20}';

    protected $description = 'Move call recordings and screenshots to Google Drive';

    public const MISSING = 'missing'; // the server file was already gone: nothing to move

    public function handle(GoogleDriveService $service): int
    {
        $drive = GoogleDrive::query()->first();
        if (! $drive?->isConnected() || ! $drive->hasKeys()) {
            return self::SUCCESS;
        }
        // One mover at a time (after a call is saved, the Move now button, the scheduler), so no file goes up twice.
        $lock = \Illuminate\Support\Facades\Cache::lock('calls:proofs-to-drive', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $this->move($service, $drive);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function move(GoogleDriveService $service, GoogleDrive $drive): void
    {
        $limit = max(1, (int) $this->option('limit'));
        $customer = DB::table('customer_calls as c')->whereNotNull('c.proof_path')->whereNull('c.proof_drive_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')->orderBy('c.id')->limit($limit)
            ->get(['c.id', 'c.phone', 'c.started_at as at', 'c.proof_path', 'c.proof_name', 'u.name as by'])->map(fn ($r) => ['customer_calls', $r]);
        $rider = DB::table('rider_calls as c')->whereNotNull('c.proof_path')->whereNull('c.proof_drive_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.handled_by')->orderBy('c.id')->limit($limit)
            ->get(['c.id', 'c.rider_phone as phone', 'c.created_at as at', 'c.proof_path', 'c.proof_name', 'u.name as by'])->map(fn ($r) => ['rider_calls', $r]);
        $moved = 0;
        foreach ($customer->concat($rider)->take($limit) as [$table, $call]) {
            if (! Storage::disk('local')->exists($call->proof_path)) {
                DB::table($table)->where('id', $call->id)->update(['proof_drive_id' => self::MISSING]);

                continue;
            }
            $mime = \App\Services\Calls\CallListService::proofMime($call->proof_path);
            $folder = $table === 'rider_calls' ? 'riders' : (str_starts_with($mime, 'audio/') ? 'records' : 'screenshots');
            // "01712345678 2026-10-15 17-40 Karim #123.m4a": findable in Drive by number, day or caller.
            $name = trim(($call->phone ?? '').' '.\Illuminate\Support\Carbon::parse($call->at)->format('Y-m-d H-i').' '.($call->by ?? '')).' #'.($table === 'rider_calls' ? 'R' : '').$call->id.'.'.strtolower(pathinfo($call->proof_path, PATHINFO_EXTENSION));
            try {
                $file = $service->upload($drive, $folder, $name, $mime, Storage::disk('local')->get($call->proof_path),
                    $call->proof_name ? 'Picked on the phone: '.$call->proof_name : null);
            } catch (RuntimeException $e) {
                Log::error('Call proof to Drive failed', ['table' => $table, 'call' => $call->id, 'error' => $e->getMessage()]);

                break; // Drive is not taking files now: the rest wait for the next run
            }
            DB::table($table)->where('id', $call->id)->update(['proof_drive_id' => $file['id']]);
            Storage::disk('local')->delete($call->proof_path);
            $moved++;
        }
        if ($moved) {
            $this->info("Moved {$moved} file(s) to Drive.");
        }
        // Files fetched from Drive to be listened to go after a day.
        foreach (Storage::disk('local')->files(\App\Services\Calls\CallProof::CACHE) as $cached) {
            if (Storage::disk('local')->lastModified($cached) < now()->subDay()->getTimestamp()) {
                Storage::disk('local')->delete($cached);
            }
        }
    }
}
