<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Packaging\BatchService;
use App\Services\Packaging\StockIssueService;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Telegram: bot button presses (webhook) and the Mini App packers open from
 * the batch message. Every press is tied to the employee whose Telegram id
 * is on their staff profile, so it counts for them.
 */
class TelegramController extends Controller
{
    public function __construct(private TelegramService $telegram) {}

    public function webhook(Request $request, BatchService $batches): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 401);
        }

        $cb = $request->input('callback_query');
        if (! $cb) {
            return response()->json(['ok' => true]);
        }
        $user = User::where('telegram_user_id', (string) ($cb['from']['id'] ?? ''))->where('is_active', true)->first();
        if (! $user) {
            $this->telegram->answerCallback((string) $cb['id'], __('Your Telegram is not linked to a staff profile.'));

            return response()->json(['ok' => true]);
        }

        [$action, $id] = array_pad(explode(':', (string) ($cb['data'] ?? '')), 2, null);
        if ($action === 'picked' && $id) {
            $batches->markPicked((int) $id, $user);
            $this->telegram->answerCallback((string) $cb['id'], __('Thanks :n, marked as picked.', ['n' => $user->name]));
        }

        return response()->json(['ok' => true]);
    }

    /** Mini App shell; it signs in with Telegram's initData, never with a password. */
    public function app(Request $request): View
    {
        return view('telegram.app', ['batch' => $request->integer('batch')]);
    }

    public function appData(Request $request, BatchService $batches): JsonResponse
    {
        $user = $this->user($request);
        $batch = DB::table('batches')->find($request->integer('batch'));
        abort_unless($batch, 404);

        return response()->json([
            'name' => $user->name,
            'batch' => $batch->batch_no,
            'lines' => $batches->pickList($batch->id)->map(fn ($l) => [
                'variant_id' => $l->variant_id, 'name' => $l->name, 'shelf' => $l->shelf_code,
                'qty' => \App\Support\Units::qty($l->qty, $l->unit),
            ]),
        ]);
    }

    public function appReport(Request $request, StockIssueService $issues): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['batch' => ['required', 'integer'], 'variant_id' => ['required', 'integer']]);
        $issues->report((int) $data['variant_id'], null, (int) $data['batch'], $user, __('From Telegram'));

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        $tg = $this->telegram->verifyInitData((string) $request->input('init_data'));
        abort_unless($tg && isset($tg['id']), 401, __('Open this from Telegram.'));
        $user = User::where('telegram_user_id', (string) $tg['id'])->where('is_active', true)->first();
        abort_unless($user, 403, __('Your Telegram is not linked to a staff profile.'));

        return $user;
    }
}
