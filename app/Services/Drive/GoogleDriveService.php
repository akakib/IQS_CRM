<?php

namespace App\Services\Drive;

use App\Models\GoogleDrive;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Google Drive for the business's own account, with plain HTTP (no package).
 * Permission asked is drive.file: IQS sees only the files and folders it made itself,
 * so it makes its own folder "IQS CRM by T5" with "Call Record" and "Call SS" inside.
 */
class GoogleDriveService
{
    public const SCOPE = 'openid email https://www.googleapis.com/auth/drive.file';

    public const FOLDERS = ['root' => 'IQS CRM by T5', 'records' => 'Call Record', 'screenshots' => 'Call SS', 'riders' => 'Rider Calls'];

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/settings/google-drive/callback';
    }

    public function authUrl(GoogleDrive $drive, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $drive->client_id, 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => self::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    /** Turns the code Google sent back into a lasting connection, then makes the folders. */
    public function connect(GoogleDrive $drive, string $code, User $user): void
    {
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => $drive->client_id, 'client_secret' => $drive->client_secret,
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        $this->fail($r, 'Google did not accept the connection');
        if (! str_contains((string) $r->json('scope'), 'drive.file')) {
            throw new RuntimeException(__('Drive permission was not ticked on the Google page. Connect again and allow Drive.'));
        }
        if (! $r->json('refresh_token')) {
            throw new RuntimeException(__('Google gave no lasting access. Connect again.'));
        }
        $claims = json_decode(base64_decode(strtr(explode('.', (string) $r->json('id_token').'..')[1], '-_', '+/')), true) ?: [];
        Cache::put($this->tokenKey($r->json('refresh_token')), $r->json('access_token'), max(60, (int) $r->json('expires_in') - 120));
        $drive->update(['refresh_token' => $r->json('refresh_token'), 'account_email' => $claims['email'] ?? null, 'folders' => null,
            'connected_at' => now(), 'connected_by' => $user->id, 'tested_at' => null, 'test_ok' => null, 'test_message' => null]);
        $this->folders($drive);
    }

    public function disconnect(GoogleDrive $drive): void
    {
        if ($drive->refresh_token) {
            Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $drive->refresh_token]); // best effort
            Cache::forget($this->tokenKey($drive->refresh_token));
        }
        $drive->update(['refresh_token' => null, 'account_email' => null, 'folders' => null, 'connected_at' => null, 'connected_by' => null,
            'tested_at' => null, 'test_ok' => null, 'test_message' => null]);
    }

    /** Puts a small file in the IQS folder, reads it back and checks it matches. The file stays so it can be seen in Drive. */
    public function test(GoogleDrive $drive): string
    {
        try {
            $body = 'IQS CRM connection test, '.now()->format('d M Y h:i A').'. This file can be deleted.';
            $file = $this->upload($drive, 'root', 'IQS connection test '.now()->format('Y-m-d H-i').'.txt', 'text/plain', $body);
            if ($this->download($drive, $file['id']) !== $body) {
                throw new RuntimeException(__('The test file went up but came back different.'));
            }
            $message = __('Drive works: a test file was put in ":f" and read back.', ['f' => self::FOLDERS['root']]);
            $drive->update(['tested_at' => now(), 'test_ok' => true, 'test_message' => $message]);

            return $message;
        } catch (RuntimeException $e) {
            $drive->update(['tested_at' => now(), 'test_ok' => false, 'test_message' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
    }

    /** @return array{id: string, name: string} */
    public function upload(GoogleDrive $drive, string $folder, string $name, string $mime, string $contents, ?string $description = null): array
    {
        $parent = $this->folders($drive)[$folder] ?? throw new RuntimeException('Unknown folder.');
        $send = fn (string $parent) => $this->api($drive)->withBody($this->multipart(array_filter(['name' => $name, 'parents' => [$parent], 'description' => $description]), $mime, $contents, $boundary = 'iqs'.bin2hex(random_bytes(12))), 'multipart/related; boundary='.$boundary)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name');
        $r = $send($parent);
        if ($r->status() === 404) { // the folder was deleted in Drive: make the folders again once
            $drive->update(['folders' => null]);
            $r = $send($this->folders($drive)[$folder]);
        }
        $this->fail($r, 'Drive did not take the file');

        return ['id' => (string) $r->json('id'), 'name' => (string) $r->json('name')];
    }

    public function download(GoogleDrive $drive, string $fileId): string
    {
        $r = $this->api($drive)->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId), ['alt' => 'media']);
        $this->fail($r, 'Drive did not give the file back');

        return $r->body();
    }

    /** The folder ids, made (or found again after a reconnect) when missing. @return array<string, string> */
    public function folders(GoogleDrive $drive): array
    {
        $have = $drive->folders ?? [];
        if (count(array_filter($have)) === count(self::FOLDERS)) {
            return $have;
        }
        $root = $this->folder($drive, self::FOLDERS['root'], null);
        $have = ['root' => $root];
        foreach (array_diff_key(self::FOLDERS, ['root' => 1]) as $key => $name) {
            $have[$key] = $this->folder($drive, $name, $root);
        }
        $drive->update(['folders' => $have]);

        return $have;
    }

    private function folder(GoogleDrive $drive, string $name, ?string $parent): string
    {
        $q = "mimeType='".self::FOLDER_MIME."' and trashed=false and name='".str_replace("'", "\\'", $name)."'".($parent ? " and '".$parent."' in parents" : " and 'root' in parents");
        $found = $this->api($drive)->get('https://www.googleapis.com/drive/v3/files', ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]);
        $this->fail($found, 'Drive could not be read');
        if ($id = $found->json('files.0.id')) {
            return $id;
        }
        $made = $this->api($drive)->post('https://www.googleapis.com/drive/v3/files?fields=id', array_filter(['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => $parent ? [$parent] : null]));
        $this->fail($made, 'Drive did not make the folder');

        return (string) $made->json('id');
    }

    private function api(GoogleDrive $drive): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken($drive))->acceptJson()->timeout(60);
    }

    private function accessToken(GoogleDrive $drive): string
    {
        if (! $drive->isConnected() || ! $drive->hasKeys()) {
            throw new RuntimeException(__('Google Drive is not connected.'));
        }

        return Cache::get($this->tokenKey($drive->refresh_token)) ?? (function () use ($drive) {
            $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $drive->client_id, 'client_secret' => $drive->client_secret,
                'refresh_token' => $drive->refresh_token, 'grant_type' => 'refresh_token',
            ]);
            if ($r->json('error') === 'invalid_grant') {
                throw new RuntimeException(__('Google no longer allows this connection (it was removed or expired). Connect again.'));
            }
            $this->fail($r, 'Google did not give access');
            Cache::put($this->tokenKey($drive->refresh_token), $r->json('access_token'), max(60, (int) $r->json('expires_in') - 120));

            return (string) $r->json('access_token');
        })();
    }

    private function tokenKey(string $refresh): string
    {
        return 'gdrive.token.'.sha1($refresh);
    }

    private function multipart(array $meta, string $mime, string $contents, string $boundary): string
    {
        return "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta)."\r\n"
            ."--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n".$contents."\r\n--{$boundary}--";
    }

    private function fail(Response $r, string $what): void
    {
        if ($r->successful()) {
            return;
        }
        $why = $r->json('error.message') ?? $r->json('error_description') ?? (is_string($r->json('error')) ? $r->json('error') : null) ?? 'HTTP '.$r->status();
        throw new RuntimeException(__($what).': '.mb_substr((string) $why, 0, 300));
    }
}
