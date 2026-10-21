<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\WebsiteStatusPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Set on website: an employee sets a record-verified website order to Record verified on the website (again, if it failed). */
class WebsiteStatusController extends Controller
{
    public function __invoke(Request $request, Order $order, WebsiteStatusPush $push): RedirectResponse|JsonResponse
    {
        abort_unless($push->canSend($order), 404);
        $ok = $push->push($order, $request->user());
        $order->refresh();
        if ($request->expectsJson()) {
            return response()->json($ok
                ? ['ok' => true, 'message' => __('Website order set to Record verified.'), 'status' => Str::headline((string) $order->external_status), 'at' => $order->website_pushed_at?->format('d M, g:i A')]
                : ['ok' => false, 'error' => __('The website did not take it: :e', ['e' => Str::limit((string) $order->website_push_error, 90)])], $ok ? 200 : 422);
        }

        return $ok
            ? back()->with('success', __('Website order set to Record verified.'))
            : back()->with('error', __('The website did not take it: :e', ['e' => $order->website_push_error]));
    }
}
