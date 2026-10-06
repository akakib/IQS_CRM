<?php

namespace App\Http\Controllers;

use App\Models\WebsiteAccount;
use App\Services\ActivityLogger;
use App\Services\Catalog\Store\WooApi;
use App\Services\Catalog\WooApiImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Owner: the website's REST API keys (typed here, stored encrypted, shown
 * masked), a connection check that only counts products, and "Pull products
 * from the website". Keys left empty on edit are kept.
 */
class WebsiteApiController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $account = WebsiteAccount::create($data + ['updated_by' => $request->user()->id]);
        $this->logger->log('website_account.created', $account, null, ['name' => $account->name, 'url' => $account->url]);

        return back()->with('success', __('Website API saved. Press Check connection.'));
    }

    public function update(WebsiteAccount $account, Request $request): RedirectResponse
    {
        $data = $this->validated($request, false);
        $changed = [];
        foreach (['consumer_key', 'consumer_secret'] as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]); // left empty: keep the saved one
            } else {
                $changed[] = $key;
            }
        }
        $account->update($data + ['updated_by' => $request->user()->id] + ($changed ? ['last_checked_at' => null, 'last_check_result' => null] : []));
        $this->logger->log('website_account.updated', $account, null, ['name' => $account->name, 'url' => $account->url, 'keys_changed' => $changed]);

        return back()->with('success', __('Website API updated.'));
    }

    /** A read-only call: how many products the website has. Proves URL and keys. */
    public function check(WebsiteAccount $account, WooApi $api): RedirectResponse
    {
        try {
            $n = $api->productCount();
            $ok = true;
            $result = __('Connected. :n products on the website.', ['n' => number_format($n)]);
        } catch (\Throwable $e) {
            $ok = false;
            $result = mb_substr($e->getMessage(), 0, 200);
        }
        $account->forceFill(['last_checked_at' => now(), 'last_check_result' => $result])->save();

        return back()->with($ok ? 'success' : 'error', $result);
    }

    /** Start pulling every product from the website; the import page does the pages one by one. */
    public function pull(Request $request, WooApiImporter $importer): RedirectResponse
    {
        try {
            $id = $importer->start($request->user()->id);
        } catch (\Throwable $e) {
            return back()->with('error', mb_substr($e->getMessage(), 0, 200));
        }

        return redirect()->route('products.import.show', $id);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:255'],
            'consumer_key' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'consumer_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
        ]);
        $data['url'] = rtrim(trim($data['url']), '/');
        foreach (['consumer_key', 'consumer_secret'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }
}
