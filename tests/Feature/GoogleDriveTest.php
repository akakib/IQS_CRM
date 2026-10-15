<?php

namespace Tests\Feature;

use App\Models\GoogleDrive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Settings > Google Drive: keys stay secret, connect through Google, folders made once, test upload read back. */
class GoogleDriveTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '123-abc.apps.googleusercontent.com';

    public function test_keys_connect_test_and_disconnect(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/settings/google-drive')->assertOk()->assertSee('Save the Client ID');
        $this->post('/settings/google-drive', ['client_id' => 'nonsense', 'client_secret' => 's'])->assertSessionHasErrors('client_id');
        $this->post('/settings/google-drive', ['client_id' => self::ID, 'client_secret' => 'top-secret-value'])->assertSessionHas('success');
        $drive = GoogleDrive::current();
        $this->assertSame('top-secret-value', $drive->client_secret);
        $this->assertStringNotContainsString('top-secret-value', (string) \DB::table('google_drive')->value('client_secret')); // encrypted at rest
        $this->get('/settings/google-drive')->assertDontSee('top-secret-value')->assertSee('Connect Google Drive');
        $this->post('/settings/google-drive', ['client_id' => self::ID, 'client_secret' => ''])->assertSessionHasNoErrors(); // empty keeps the secret
        $this->assertSame('top-secret-value', $drive->fresh()->client_secret);

        $go = $this->post('/settings/google-drive/connect');
        $url = $go->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('drive.file', $q['scope']);
        $this->assertStringEndsWith('/settings/google-drive/callback', $q['redirect_uri']);

        $this->get('/settings/google-drive/callback?state=wrong&code=x')->assertSessionHas('error'); // forged state
        $this->post('/settings/google-drive/connect');
        $this->get('/settings/google-drive/callback?state='.session('google_drive_state').'&error=access_denied')->assertSessionHas('error');

        $stored = [];
        $made = 0;
        Http::fake(function ($request) use (&$stored, &$made) {
            $u = $request->url();
            if (str_starts_with($u, 'https://oauth2.googleapis.com/token')) {
                $jwt = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'files@example.com'])), '+/', '-_'), '=').'.y';
                return Http::response(['access_token' => 'at', 'expires_in' => 3600, 'refresh_token' => 'rt', 'scope' => 'openid email https://www.googleapis.com/auth/drive.file', 'id_token' => $jwt]);
            }
            if (str_starts_with($u, 'https://www.googleapis.com/drive/v3/files?') && $request->method() === 'GET') {
                return Http::response(['files' => []]);
            }
            if (str_starts_with($u, 'https://www.googleapis.com/drive/v3/files?fields=id')) {
                return Http::response(['id' => 'folder'.(++$made)]);
            }
            if (str_starts_with($u, 'https://www.googleapis.com/upload/')) {
                preg_match('~Content-Type: text/plain\r\n\r\n(.*)\r\n--iqs\w+--$~s', $request->body(), $m);
                $stored['f1'] = $m[1] ?? '';
                return Http::response(['id' => 'f1', 'name' => 'test.txt']);
            }
            if (str_starts_with($u, 'https://www.googleapis.com/drive/v3/files/f1')) {
                return Http::response($stored['f1'] ?? '');
            }
            if (str_starts_with($u, 'https://oauth2.googleapis.com/revoke')) {
                return Http::response([]);
            }
            return Http::response(['error' => ['message' => 'unexpected '.$u]], 500);
        });

        $this->post('/settings/google-drive/connect');
        $this->get('/settings/google-drive/callback?state='.session('google_drive_state').'&code=abc')->assertSessionHas('success');
        $drive = $drive->fresh();
        $this->assertTrue($drive->isConnected());
        $this->assertSame('files@example.com', $drive->account_email);
        $this->assertSame(['root' => 'folder1', 'records' => 'folder2', 'screenshots' => 'folder3'], $drive->folders);

        $this->post('/settings/google-drive/test')->assertSessionHas('success');
        $this->assertTrue($drive->fresh()->test_ok);
        $this->assertSame(3, $made); // folders are made once, not on every upload
        $this->get('/settings/google-drive')->assertSee('Last test passed')->assertSee('files@example.com')->assertDontSee('top-secret-value');

        $this->post('/settings/google-drive/disconnect')->assertSessionHas('success');
        $this->assertFalse($drive->fresh()->isConnected());
        $this->assertSame(self::ID, $drive->fresh()->client_id); // keys stay for the next connect
    }

    public function test_only_access_managers_reach_it(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->role(['settings.view', 'settings.edit'], [], 'Manager')->id);
        $this->actingAs($user)->get('/settings/google-drive')->assertForbidden();
        $this->post('/settings/google-drive', ['client_id' => self::ID, 'client_secret' => 'x'])->assertForbidden();
        $this->get('/settings/google-drive/callback?state=a&code=b')->assertForbidden();
    }
}
