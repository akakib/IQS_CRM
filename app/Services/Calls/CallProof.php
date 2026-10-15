<?php

namespace App\Services\Calls;

use App\Support\Phone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The recording or call-log screenshot of a call, checked before it is kept:
 *  - it really is a recording (or a picture), judged by its content;
 *  - the same file was not used for another call;
 *  - a number in its name is the number of this call;
 *  - a file from an earlier day is used only when the person says it is the right one (and it is marked).
 * The name it had on the phone is kept, so a manager can see what was picked.
 */
class CallProof
{
    /** Older than this (by the file's own time) counts as an earlier file. */
    private const FRESH_HOURS = 12;

    /**
     * @param  string[]  $phones  numbers this call was with (any one may be in the file's name)
     * @return array{ext: string, name: string, size: int, hash: string, old: bool}
     */
    public function check(UploadedFile $file, ?bool $recording, array $phones, ?string $modifiedMs, bool $oldOk, string $field = 'proof'): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages([$field => $m]);
        // null: either a recording or a screenshot will do.
        $ext = ($recording === null ? CallListService::proofExtension($file, true) ?? CallListService::proofExtension($file, false) : CallListService::proofExtension($file, $recording))
            ?? $fail(match ($recording) {
                true => __('This is not a recording. Add the call recording (m4a, mp3, amr...).'),
                false => __('This is not a picture. Add a screenshot of the call log.'),
                null => __('This is not a recording or a picture. Add the call recording or a screenshot of the call log.'),
            });

        $hash = sha1_file($file->getRealPath());
        $used = DB::table('customer_calls')->where('proof_hash', $hash)->exists() || DB::table('rider_calls')->where('proof_hash', $hash)->exists();
        if ($used) {
            $fail(__('This file was already used for another call. Add the file of this call.'));
        }

        $name = mb_substr(trim($file->getClientOriginalName()), 0, 190);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $inName = Phone::extract($base);
        $phones = array_values(array_filter(array_map(fn ($p) => Phone::normalize($p), $phones)));
        if ($inName && $phones && ! array_intersect($inName, $phones)) {
            $fail(__('This file is of :x, not of :y. Pick the file of this call.', ['x' => $inName[0], 'y' => $phones[0]]));
        }

        $when = self::fileDate($base, $modifiedMs);
        $old = $when !== null && $when->lt(self::freshFrom($when));
        if ($old && ! $oldOk) {
            $fail(__('This file is from :d. Tick "It is the right file" if it is, or pick the file of this call.', ['d' => $when->format('d M')]));
        }

        return ['ext' => $ext, 'name' => $name, 'size' => (int) $file->getSize(), 'hash' => $hash, 'old' => $old];
    }

    /** The file's day from its name (2026-10-15, 20261015...), else its time from the phone. */
    public static function fileDate(string $base, ?string $modifiedMs): ?Carbon
    {
        if (preg_match('/(?<!\d)(20\d\d)[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])/', $base, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        }
        if ($modifiedMs !== null && ctype_digit($modifiedMs) && (int) $modifiedMs > 0) {
            return Carbon::createFromTimestampMs((int) $modifiedMs)->setTimezone(config('app.timezone'));
        }

        return null;
    }

    /** A day from the name counts from today; an exact time from the phone counts the last hours. */
    private static function freshFrom(Carbon $when): Carbon
    {
        return $when->format('H:i:s') === '00:00:00' ? today() : now()->subHours(self::FRESH_HOURS);
    }

    /** Columns to save with the call. */
    public static function columns(string $path, array $checked): array
    {
        return ['proof_path' => $path, 'proof_name' => $checked['name'], 'proof_size' => $checked['size'], 'proof_hash' => $checked['hash'], 'proof_old' => $checked['old']];
    }

    /** Who made each kind of call. */
    public const TABLES = ['customer_calls' => 'user_id', 'rider_calls' => 'handled_by'];

    /**
     * A call's proof for this person: only who made the call and managers. On the server until it moves to Drive.
     *
     * @return ?object{path: string, drive_id: ?string}
     */
    public function find(string $table, int $id, \App\Models\User $user): ?object
    {
        $by = self::TABLES[$table];
        $call = DB::table($table)->find($id, [$by.' as by', 'proof_path', 'proof_drive_id']);
        if (! $call?->proof_path || ((int) $call->by !== $user->id && ! $user->can('calls.manage'))) {
            return null;
        }
        $drive = $call->proof_drive_id && $call->proof_drive_id !== \App\Console\Commands\MoveCallProofsToDrive::MISSING ? $call->proof_drive_id : null;
        if (! $drive && ! \Illuminate\Support\Facades\Storage::disk('local')->exists($call->proof_path)) {
            return null;
        }

        return (object) ['path' => $call->proof_path, 'drive_id' => $drive];
    }

    /** The file itself: its own type only, never sniffed, nothing in it can run; from Drive through IQS (nothing in Drive is shared). */
    public function response(string $table, int $id, \App\Models\User $user): \Symfony\Component\HttpFoundation\Response
    {
        $file = $this->find($table, $id, $user) ?? abort(404);
        $headers = [
            'Content-Type' => CallListService::proofMime($file->path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; sandbox",
        ];
        if (! $file->drive_id) {
            return \Illuminate\Support\Facades\Storage::disk('local')->response($file->path, null, $headers);
        }
        try {
            $body = app(\App\Services\Drive\GoogleDriveService::class)->download(\App\Models\GoogleDrive::current(), $file->drive_id);
        } catch (\RuntimeException) {
            abort(503, __('The file is in Google Drive, but Drive did not give it back now. If Drive was disconnected, connect it again in Settings.'));
        }

        return response()->stream(function () use ($body) {
            echo $body;
        }, 200, $headers + ['Content-Length' => strlen($body), 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Checks and keeps an optional proof sent with a call; the columns to save (none without a file). */
    public function take(\Illuminate\Http\Request $request, array $phones, string $dir, string $base): array
    {
        $file = $request->file('proof');
        if (! $file) {
            return [];
        }
        $request->validate(['proof' => ['file', 'max:25600'], 'proof_modified' => ['nullable', 'string', 'max:20'], 'proof_old_ok' => ['nullable', 'boolean']]);
        $checked = $this->check($file, null, $phones, $request->input('proof_modified'), $request->boolean('proof_old_ok'));

        return self::columns(self::store($file, $dir, $base.'-'.now()->format('YmdHis'), $checked['ext']), $checked);
    }

    /** Keeps the file on the server until it moves to Drive. */
    public static function store(UploadedFile $file, string $dir, string $base, string $ext): string
    {
        return $file->storeAs($dir, $base.'.'.$ext, 'local');
    }
}
