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
        $calls = DB::table('customer_calls as c')->whereNotNull('c.proof_path')->whereNull('c.proof_drive_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderBy('c.id')->limit(max(1, (int) $this->option('limit')))
            ->get(['c.id', 'c.phone', 'c.started_at', 'c.proof_path', 'u.name as by']);
        $moved = 0;
        foreach ($calls as $call) {
            if (! Storage::disk('local')->exists($call->proof_path)) {
                DB::table('customer_calls')->where('id', $call->id)->update(['proof_drive_id' => self::MISSING]);

                continue;
            }
            $ext = strtolower(pathinfo($call->proof_path, PATHINFO_EXTENSION));
            $recording = str_starts_with(\App\Services\Calls\CallListService::proofMime($call->proof_path), 'audio/');
            // "01712345678 2026-10-15 17-40 Karim #123.m4a": findable in Drive by number, day or caller.
            $name = trim($call->phone.' '.\Illuminate\Support\Carbon::parse($call->started_at)->format('Y-m-d H-i').' '.($call->by ?? '')).' #'.$call->id.'.'.$ext;
            try {
                $file = $service->upload($drive, $recording ? 'records' : 'screenshots', $name,
                    \App\Services\Calls\CallListService::proofMime($call->proof_path), Storage::disk('local')->get($call->proof_path));
            } catch (RuntimeException $e) {
                Log::error('Call proof to Drive failed', ['call' => $call->id, 'error' => $e->getMessage()]);

                break; // Drive is not taking files now: the rest wait for the next run
            }
            DB::table('customer_calls')->where('id', $call->id)->update(['proof_drive_id' => $file['id']]);
            Storage::disk('local')->delete($call->proof_path);
            $moved++;
        }
        if ($moved) {
            $this->info("Moved {$moved} file(s) to Drive.");
        }

        return self::SUCCESS;
    }
}
