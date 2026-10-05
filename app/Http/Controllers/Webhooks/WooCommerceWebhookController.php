<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Orders\WooOrderIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public endpoint called by WooCommerce (Settings > Advanced > Webhooks,
 * topic "Order created", delivery URL /webhooks/woocommerce). Authenticity:
 * X-WC-Webhook-Signature = base64(HMAC-SHA256(raw body, secret)). The raw
 * payload is stored first, the reply is immediate, and the order is built
 * after the response is sent (no queue worker needed on shared hosting).
 */
class WooCommerceWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('store.woocommerce.webhook_secret');
        $body = $request->getContent();

        // WooCommerce pings the URL once when the webhook is saved.
        if ($request->has('webhook_id') && ! $request->header('X-WC-Webhook-Topic')) {
            return response()->json(['status' => 'pong']);
        }

        if ($secret === '') {
            return response()->json(['message' => 'Webhook secret is not configured'], 503);
        }
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        if (! hash_equals($expected, (string) $request->header('X-WC-Webhook-Signature'))) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $payload = json_decode($body, true);
        if (! in_array($topic, ['order.created', 'order.updated'], true) || ! isset($payload['id'])) {
            return response()->json(['status' => 'ignored']);
        }

        // One row per distinct body: a retry of the same webhook is a duplicate, a real change is new.
        $externalId = 'order:'.$payload['id'].':'.substr(sha1($body), 0, 16);
        $inboxId = DB::table('integration_inbox')->where('source', 'woocommerce')->where('external_id', $externalId)->value('id');
        if ($inboxId) {
            return response()->json(['status' => 'duplicate']);
        }

        $inboxId = DB::table('integration_inbox')->insertGetId([
            'source' => 'woocommerce', 'external_id' => $externalId, 'topic' => $topic,
            'payload' => $body, 'status' => 'received', 'received_at' => now(),
        ]);

        app()->terminating(fn () => app(WooOrderIntake::class)->process($inboxId));

        return response()->json(['status' => 'received']);
    }
}
