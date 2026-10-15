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

    public function test_call_files_move_to_drive_and_still_open_from_iqs(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $owner = $this->owner();
        $caller = User::factory()->create(['name' => 'Rakin']);
        $other = User::factory()->create();
        $call = fn (string $path) => \DB::table('customer_calls')->insertGetId(['phone' => '01711111111', 'direction' => 'out', 'user_id' => $caller->id,
            'started_at' => '2026-10-15 17:40:00', 'outcome' => 'answered', 'proof_path' => $path]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('call-proofs/1/a.m4a', 'AUDIO');
        \Illuminate\Support\Facades\Storage::disk('local')->put('call-proofs/1/b.png', 'PNG');
        $rec = $call('call-proofs/1/a.m4a');
        $shot = $call('call-proofs/1/b.png');
        $gone = $call('call-proofs/1/lost.m4a'); // its server file was already gone

        $sent = [];
        $down = false;
        Http::fake(function ($request) use (&$sent, &$down) {
            $u = $request->url();
            if (str_starts_with($u, 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'at', 'expires_in' => 3600]);
            }
            if (str_starts_with($u, 'https://www.googleapis.com/upload/')) {
                if ($down) {
                    return Http::response(['error' => ['message' => 'Backend Error']], 500);
                }
                preg_match('/"name":"([^"]+)","parents":\["(\w+)"\]/', $request->body(), $m);
                $sent[] = [stripslashes($m[1] ?? ''), $m[2] ?? ''];
                return Http::response(['id' => 'd'.count($sent), 'name' => $m[1] ?? '']);
            }
            if (str_starts_with($u, 'https://www.googleapis.com/drive/v3/files/d1')) {
                return Http::response('AUDIO-FROM-DRIVE');
            }
            return Http::response(['error' => ['message' => 'nope']], 404);
        });

        // Not connected: nothing moves, the files keep opening from the server.
        $this->artisan('calls:proofs-to-drive')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull(\DB::table('customer_calls')->where('id', $rec)->value('proof_drive_id'));

        GoogleDrive::current()->update(['client_id' => self::ID, 'client_secret' => 's', 'refresh_token' => 'rt', 'folders' => ['root' => 'R', 'records' => 'REC', 'screenshots' => 'SS']]);
        $down = true;

        // Drive fails: the files stay on the server and are tried again later.
        $this->artisan('calls:proofs-to-drive')->assertSuccessful();
        $this->assertNull(\DB::table('customer_calls')->where('id', $rec)->value('proof_drive_id'));
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('call-proofs/1/a.m4a');

        $down = false;
        $this->artisan('calls:proofs-to-drive')->assertSuccessful();
        $this->assertSame([['01711111111 2026-10-15 17-40 Rakin #'.$rec.'.m4a', 'REC'], ['01711111111 2026-10-15 17-40 Rakin #'.$shot.'.png', 'SS']], $sent);
        $this->assertSame('d1', \DB::table('customer_calls')->where('id', $rec)->value('proof_drive_id'));
        $this->assertSame('missing', \DB::table('customer_calls')->where('id', $gone)->value('proof_drive_id'));
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('call-proofs/1/a.m4a'); // removed only after Drive had it

        // Still opens from IQS for the caller and managers, with its own type; never for others.
        $caller->roles()->attach($this->role(['calls.work'], [], 'Caller')->id);
        $res = $this->actingAs($caller)->get(route('calls.proof', $rec))->assertOk()->assertHeader('Content-Type', 'audio/mp4')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('AUDIO-FROM-DRIVE', $res->streamedContent());
        $this->actingAs($owner)->get(route('calls.proof', $rec))->assertOk();
        $this->actingAs($other)->get(route('calls.proof', $rec))->assertNotFound();
        $this->actingAs($owner)->get(route('calls.proof', $shot))->assertStatus(503); // Drive did not give it back
        $this->get(route('calls.proof', $gone))->assertNotFound();
        $this->get('/settings/google-drive')->assertSee('2 call files in Drive.');

        // Move now: nothing waits, so it says so.
        $this->post('/settings/google-drive/move')->assertSessionHas('success', 'Nothing is waiting: every call file is in Drive.');
        \Illuminate\Support\Facades\Storage::disk('local')->put('call-proofs/1/c.m4a', 'MORE');
        $more = $call('call-proofs/1/c.m4a');
        $this->get('/settings/google-drive')->assertSee('1 waiting on the server.')->assertSee('Move now');
        $this->post('/settings/google-drive/move')->assertSessionHas('success', '1 moved to Drive. Nothing is waiting now.');
        $this->assertSame('d3', \DB::table('customer_calls')->where('id', $more)->value('proof_drive_id'));
        $sent = array_slice($sent, 0, 2);

        // Another mover already busy: this run leaves the files alone.
        \Illuminate\Support\Facades\Storage::disk('local')->put('call-proofs/1/d.m4a', 'X');
        $busy = $call('call-proofs/1/d.m4a');
        $lock = \Illuminate\Support\Facades\Cache::lock('calls:proofs-to-drive', 600);
        $lock->get();
        $this->artisan('calls:proofs-to-drive')->assertSuccessful();
        $this->assertNull(\DB::table('customer_calls')->where('id', $busy)->value('proof_drive_id'));
        $lock->release();
        \DB::table('customer_calls')->where('id', $busy)->delete();

        // Running again moves nothing more.
        $this->artisan('calls:proofs-to-drive')->assertSuccessful();
        $this->assertCount(2, $sent);
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
