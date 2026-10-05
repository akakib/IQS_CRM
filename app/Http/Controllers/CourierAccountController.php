<?php

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Owner: courier API accounts. Keys are typed here (not in the server .env),
 * stored encrypted, shown masked, and can be checked with a harmless call
 * (Steadfast get_balance). Editing: leave a key empty to keep it.
 */
class CourierAccountController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index(): View
    {
        return view('settings.couriers', [
            'accounts' => CourierAccount::orderByDesc('is_default')->orderBy('id')->get(),
            'webhookUrl' => route('webhooks.steadfast'),
            'webhookToken' => (string) config('courier.steadfast.webhook_token'),
            'liveBooking' => ! app(\App\Services\Courier\CourierManager::class)->isForcedFake(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $account = DB::transaction(function () use ($data, $request) {
            $first = ! CourierAccount::where('courier', 'steadfast')->exists();
            $account = CourierAccount::create($data + ['courier' => 'steadfast', 'is_default' => $first, 'updated_by' => $request->user()->id]);
            if ($request->boolean('is_default')) {
                $this->makeDefault($account);
            }

            return $account;
        });
        $this->logger->log('courier_account.created', $account, null, ['name' => $account->name]);

        return back()->with('success', __('Account ":n" saved.', ['n' => $account->name]));
    }

    public function update(CourierAccount $account, Request $request): RedirectResponse
    {
        $data = $this->validated($request, $account);
        foreach (['api_key', 'secret_key'] as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]); // left empty: keep the saved key
            }
        }
        $changedKeys = array_keys(array_intersect_key($data, array_flip(['api_key', 'secret_key'])));
        $account->update($data + ['updated_by' => $request->user()->id] + ($changedKeys ? ['last_checked_at' => null, 'last_check_result' => null] : []));
        if ($request->boolean('is_default')) {
            $this->makeDefault($account);
        }
        // Never log the keys themselves: only that they changed.
        $this->logger->log('courier_account.updated', $account, null, ['name' => $account->name, 'keys_changed' => $changedKeys, 'active' => $account->is_active]);

        return back()->with('success', __('Account ":n" updated.', ['n' => $account->name]));
    }

    /** A harmless call (balance) to prove the keys work. Books nothing. */
    public function check(CourierAccount $account): RedirectResponse
    {
        try {
            $response = Http::withHeaders(['Api-Key' => $account->api_key, 'Secret-Key' => $account->secret_key])
                ->baseUrl((string) config('courier.steadfast.base_url'))->acceptJson()->timeout(15)->get('get_balance');
            $ok = $response->successful() && $response->json('current_balance') !== null;
            $result = $ok
                ? __('Connected. Balance ৳:b', ['b' => number_format((float) $response->json('current_balance'), 2)])
                : __('Refused: HTTP :s :m', ['s' => $response->status(), 'm' => mb_substr((string) ($response->json('message') ?? ''), 0, 120)]);
        } catch (\Throwable $e) {
            $ok = false;
            $result = __('Could not reach Steadfast: :m', ['m' => mb_substr($e->getMessage(), 0, 150)]);
        }
        $account->forceFill(['last_checked_at' => now(), 'last_check_result' => $result])->save();

        return back()->with($ok ? 'success' : 'error', $result);
    }

    private function validated(Request $request, ?CourierAccount $account): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_key' => [$account ? 'nullable' : 'required', 'string', 'max:255'],
            'secret_key' => [$account ? 'nullable' : 'required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : ($account?->is_active ?? true);
        foreach (['api_key', 'secret_key'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }

    private function makeDefault(CourierAccount $account): void
    {
        CourierAccount::where('courier', $account->courier)->whereKeyNot($account->id)->update(['is_default' => false]);
        $account->forceFill(['is_default' => true])->save();
    }
}
