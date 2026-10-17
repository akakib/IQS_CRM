<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\WebsiteStatusPush;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Send to website: try again to set a record-verified website order to Record verified there. */
class WebsiteStatusController extends Controller
{
    public function __invoke(Request $request, Order $order, WebsiteStatusPush $push): RedirectResponse
    {
        abort_unless($push->applies($order), 404);

        return $push->push($order, $request->user())
            ? back()->with('success', __('Website order set to Record verified.'))
            : back()->with('error', __('The website did not take it: :e', ['e' => $order->fresh()->website_push_error]));
    }
}
