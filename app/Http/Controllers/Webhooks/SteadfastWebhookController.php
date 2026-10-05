<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Services\Courier\CourierManager;
use App\Services\Courier\CourierUpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Called by Steadfast (merchant panel > webhook, Bearer token). The full raw
 * payload is stored before anything else, the reply is immediate, and the
 * update is applied after the response.
 */
class SteadfastWebhookController extends Controller
{
    public function __invoke(Request $request, CourierManager $courier): JsonResponse
    {
        $given = (string) $request->bearerToken();
        $valid = collect(CourierAccount::webhookTokens('steadfast'))->contains(fn ($t) => hash_equals($t, $given));
        if (! $valid) {
            // Remembered so Settings > Courier accounts can say "Steadfast called, but the token did not match".
            Cache::put('steadfast:webhook:rejected', now()->toIso8601String(), now()->addDays(30));

            return response()->json(['message' => 'Invalid token'], 401);
        }
        Cache::put('steadfast:webhook:accepted', now()->toIso8601String(), now()->addDays(30));

        $payload = json_decode($request->getContent(), true) ?: [];
        if (($payload['notification_type'] ?? null) === 'iqs_test') {
            return response()->json(['status' => 'test ok']); // Settings "Send a test": proves the URL and token, stores nothing
        }

        $body = $request->getContent();
        $key = sha1($body);
        if (DB::table('courier_events')->where('idempotency_key', $key)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $eventId = DB::table('courier_events')->insertGetId([
            'courier' => 'steadfast',
            'consignment_id' => isset($payload['consignment_id']) ? (string) $payload['consignment_id'] : null,
            'notification_type' => $payload['notification_type'] ?? null,
            'payload' => $body,
            'idempotency_key' => $key,
            'created_at' => now(),
        ]);

        $update = $courier->driver()->parseWebhook($payload);
        if ($update) {
            app()->terminating(fn () => app(CourierUpdateProcessor::class)->apply($update, $eventId));
        } else {
            DB::table('courier_events')->where('id', $eventId)->update(['processed_at' => now(), 'error' => 'Unrecognised payload']);
        }

        return response()->json(['status' => 'received']);
    }
}
