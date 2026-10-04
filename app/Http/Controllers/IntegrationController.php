<?php

namespace App\Http\Controllers;

use App\Services\Orders\WooOrderIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Owner: website connection details and the incoming-orders inbox. */
class IntegrationController extends Controller
{
    public function index(): View
    {
        return view('settings.integrations', [
            'webhookUrl' => route('webhooks.woocommerce'),
            'secret' => (string) config('store.woocommerce.webhook_secret'),
            'steadfastUrl' => route('webhooks.steadfast'),
            'steadfastToken' => (string) config('courier.steadfast.webhook_token'),
            'storeDriver' => app(\App\Services\Catalog\Store\StoreDriver::class)->name(),
            'inbox' => DB::table('integration_inbox')->orderByDesc('id')->limit(30)
                ->get(['id', 'source', 'external_id', 'topic', 'status', 'error', 'order_id', 'received_at']),
            'counts' => DB::table('integration_inbox')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function retry(int $inbox, WooOrderIntake $intake): RedirectResponse
    {
        DB::table('integration_inbox')->where('id', $inbox)->where('status', 'failed')->update(['status' => 'received', 'error' => null]);
        $order = $intake->process($inbox);

        return back()->with($order ? 'success' : 'error', $order ? __('Imported as :no.', ['no' => $order->order_no]) : __('Still failing. See the error.'));
    }
}
