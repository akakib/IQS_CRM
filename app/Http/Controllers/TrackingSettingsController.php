<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Ad tracking: when the Purchase event is sent, and the send log. */
class TrackingSettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.tracking', [
            'events' => DB::table('tracking_event_settings')->orderBy('id')->get(),
            'logs' => DB::table('tracking_event_logs as l')->join('orders as o', 'o.id', '=', 'l.order_id')
                ->orderByDesc('l.id')->limit(30)->get(['l.*', 'o.order_no']),
            'metaReady' => config('services.meta.pixel_id') && config('services.meta.capi_token'),
            'production' => app()->isProduction(),
        ]);
    }

    public function update(Request $request, int $event, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'fire_on' => ['required', Rule::in(['order_created', 'record_verified', 'confirmed', 'delivered'])],
            'value_basis' => ['required', Rule::in(['order_total', 'product_subtotal', 'delivered_amount'])],
            'channels' => ['array'],
            'channels.*' => [Rule::in(array_keys(\App\Models\Order::CHANNELS))],
        ]);
        $data['channels'] = json_encode(array_values($data['channels'] ?? []));
        $data['is_active'] = $request->boolean('is_active');

        $before = (array) DB::table('tracking_event_settings')->where('id', $event)->first(['fire_on', 'value_basis', 'channels', 'is_active']);
        abort_unless($before, 404);
        DB::table('tracking_event_settings')->where('id', $event)->update($data + ['updated_at' => now()]);
        $logger->log('tracking_event.updated', ['tracking_event', $event], $before, $data);

        return back()->with('success', __('Saved.'));
    }
}
