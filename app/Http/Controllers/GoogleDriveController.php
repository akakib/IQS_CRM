<?php

namespace App\Http\Controllers;

use App\Models\GoogleDrive;
use App\Services\ActivityLogger;
use App\Services\Drive\GoogleDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/** Settings > Google Drive: the keys (typed by the owner), connecting the account, and a test upload. */
class GoogleDriveController extends Controller
{
    public function __construct(private GoogleDriveService $drive) {}

    public function index(): View
    {
        return view('settings.google-drive', [
            'drive' => GoogleDrive::current(),
            'redirectUri' => GoogleDriveService::redirectUri(),
            'folders' => GoogleDriveService::FOLDERS,
        ]);
    }

    public function save(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $drive = GoogleDrive::current();
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[\w.-]+\.apps\.googleusercontent\.com$/'],
            'client_secret' => [$drive->client_secret ? 'nullable' : 'required', 'string', 'max:255'],
        ], ['client_id.regex' => __('This is not a Client ID (it ends with .apps.googleusercontent.com).')]);
        $changed = $data['client_id'] !== $drive->client_id || filled($data['client_secret'] ?? null);
        $drive->client_id = trim($data['client_id']);
        if (filled($data['client_secret'] ?? null)) {
            $drive->client_secret = trim($data['client_secret']);
        }
        $drive->save();
        if ($changed && $drive->isConnected()) {
            $this->drive->disconnect($drive); // new keys: the old connection belonged to the old ones
        }
        $logger->log('google_drive.keys_saved', ['google_drive', $drive->id], null, ['client_id' => $drive->client_id]);

        return to_route('settings.google-drive')->with('success', $changed ? __('Keys saved. Now press Connect Google Drive.') : __('Saved.'));
    }

    public function connect(Request $request): RedirectResponse
    {
        $drive = GoogleDrive::current();
        if (! $drive->hasKeys()) {
            return to_route('settings.google-drive')->with('error', __('Save the Client ID and Client secret first.'));
        }
        $state = Str::random(40);
        $request->session()->put('google_drive_state', $state);

        return redirect()->away($this->drive->authUrl($drive, $state));
    }

    public function callback(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $back = redirect()->route('settings.google-drive');
        $state = $request->session()->pull('google_drive_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state'))) {
            return $back->with('error', __('The Google page took too long or was opened twice. Press Connect again.'));
        }
        if ($request->filled('error')) {
            return $back->with('error', $request->query('error') === 'access_denied'
                ? __('Not connected: permission was not given on the Google page.') : __('Google said: :e', ['e' => Str::limit((string) $request->query('error'), 100)]));
        }
        $drive = GoogleDrive::current();
        try {
            $this->drive->connect($drive, (string) $request->query('code'), $request->user());
        } catch (RuntimeException $e) {
            return $back->with('error', $e->getMessage());
        }
        $logger->log('google_drive.connected', ['google_drive', $drive->id], null, ['account' => $drive->account_email]);

        return $back->with('success', __('Google Drive connected. Press Test upload to check it.'));
    }

    public function test(): RedirectResponse
    {
        try {
            return to_route('settings.google-drive')->with('success', $this->drive->test(GoogleDrive::current()));
        } catch (RuntimeException $e) {
            return to_route('settings.google-drive')->with('error', $e->getMessage());
        }
    }

    public function disconnect(ActivityLogger $logger): RedirectResponse
    {
        $drive = GoogleDrive::current();
        $was = $drive->account_email;
        $this->drive->disconnect($drive);
        $logger->log('google_drive.disconnected', ['google_drive', $drive->id], ['account' => $was], null);

        return to_route('settings.google-drive')->with('success', __('Google Drive disconnected. Files already in Drive stay there.'));
    }
}
