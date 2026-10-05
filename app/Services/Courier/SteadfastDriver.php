<?php

namespace App\Services\Courier;

use App\Services\Courier\Data\BookingResult;
use App\Services\Courier\Data\CourierUpdate;
use App\Services\Courier\Data\FraudCheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real Steadfast (https://portal.packzy.com/api/v1, Api-Key + Secret-Key
 * headers). Uses only endpoints already proven in production elsewhere:
 * create_order, status_by_cid, get_balance. Bulk booking loops create_order
 * (one parcel per call) until the bulk endpoint is confirmed in Steadfast's
 * in-panel guide. Fraud check and tracking history are not wired yet: they
 * refuse loudly instead of guessing an endpoint.
 */
class SteadfastDriver implements CourierDriver
{
    public function __construct(private array $config = []) {}

    public function name(): string
    {
        return 'steadfast';
    }

    public function bookBulk(array $requests): array
    {
        $results = [];
        foreach ($requests as $req) {
            try {
                $response = $this->client()->post('create_order', [
                    'invoice' => $req->invoice,
                    'recipient_name' => $req->recipientName,
                    'recipient_phone' => $req->recipientPhone,
                    'recipient_address' => $req->recipientAddress,
                    'cod_amount' => $req->codAmount,
                    'note' => $req->note,
                ]);
                $c = $response->json('consignment') ?? [];
                $results[$req->invoice] = $response->successful() && ! empty($c['consignment_id'])
                    ? new BookingResult($req->invoice, true, (string) $c['consignment_id'], $c['tracking_code'] ?? null, $c['status'] ?? null, raw: $response->json())
                    : new BookingResult($req->invoice, false, error: 'Steadfast: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300), raw: $response->json() ?? [],
                        kind: match (true) {
                            in_array($response->status(), [401, 403], true) => 'auth',
                            $response->status() >= 400 && $response->status() < 500 && $response->status() !== 429 => 'rejected', // wrong phone, address, COD, duplicate invoice
                            default => 'temporary',
                        });
            } catch (\Throwable $e) {
                $results[$req->invoice] = new BookingResult($req->invoice, false, error: $e->getMessage(), kind: str_contains($e->getMessage(), 'keys are missing') ? 'auth' : 'temporary');
            }
        }

        return $results;
    }

    public function invoiceBooked(string $invoice): ?bool
    {
        try {
            $response = $this->client()->get('status_by_invoice/'.rawurlencode($invoice));
        } catch (\Throwable) {
            return null;
        }
        if ($response->status() === 404) {
            return false;
        }

        return $response->successful() && $response->json('delivery_status') ? true : null;
    }

    public function statusByInvoice(string $invoice): ?CourierUpdate
    {
        $cid = DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('o.order_no', $invoice)->where('s.is_active', true)->value('s.consignment_id');
        if (! $cid) {
            return null;
        }
        $response = $this->client()->get("status_by_cid/{$cid}");
        if (! $response->successful()) {
            throw new RuntimeException("Steadfast status_by_cid failed: HTTP {$response->status()}");
        }

        return new CourierUpdate('delivery_status', (string) $cid, $invoice, strtolower((string) $response->json('delivery_status')), raw: $response->json());
    }

    public function trackingByInvoice(string $invoice): array
    {
        return []; // only the webhook's tracking_update messages are used for now
    }

    public function fraudCheck(string $phone): FraudCheckResult
    {
        throw new RuntimeException('Steadfast fraud check endpoint is not confirmed yet; ask before enabling.');
    }

    public function parseWebhook(array $payload): ?CourierUpdate
    {
        return SteadfastPayload::parse($payload);
    }

    private function client()
    {
        if (blank($this->config['api_key'] ?? null) || blank($this->config['secret_key'] ?? null)) {
            throw new RuntimeException('Steadfast API keys are missing (STEADFAST_API_KEY / STEADFAST_SECRET_KEY).');
        }

        return Http::withHeaders(['Api-Key' => $this->config['api_key'], 'Secret-Key' => $this->config['secret_key']])
            ->baseUrl($this->config['base_url'] ?? 'https://portal.packzy.com/api/v1')->acceptJson()->timeout(20);
    }
}
