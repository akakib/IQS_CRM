<?php

namespace App\Http\Controllers;

use App\Models\StatusReason;
use App\Services\Work\BreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Break button (every page header) and the Start work button on the break screen. */
class BreakController extends Controller
{
    public function __construct(private BreakService $breaks) {}

    /** Loaded only when someone opens the Break dialog, so pages do not pay for it. */
    public function reasons(): JsonResponse
    {
        return response()->json(collect(StatusReason::options('break'))->map(fn ($label, $id) => ['id' => $id, 'label' => __($label)])->values());
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['reason_id' => ['required', 'integer']]);
        $this->breaks->start($request->user(), (int) $data['reason_id']);

        return back();
    }

    public function end(Request $request): RedirectResponse
    {
        $this->breaks->end($request->user());

        return back()->with('success', __('Welcome back.'));
    }
}
