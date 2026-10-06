<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A stored product webhook (integration_inbox row): created / updated applies
 * the product as the website sent it, deleted trashes it here. The website is
 * the master for product data, so nothing is sent back.
 */
class WooProductIntake
{
    public function __construct(private WooApiImporter $importer) {}

    public function process(int $inboxId): ?string
    {
        $inbox = DB::table('integration_inbox')->where('id', $inboxId)->first();
        if (! $inbox || $inbox->status === 'processed') {
            return null;
        }
        $p = json_decode($inbox->payload, true) ?: [];

        return Cache::lock('woo:product:'.($p['id'] ?? 0), 30)->block(15, function () use ($inboxId, $inbox, $p) {
            try {
                $result = $inbox->topic === 'product.deleted'
                    ? ($this->importer->removeProduct((string) $p['id']) ? 'deleted' : 'skipped')
                    : $this->importer->applyProduct($p);
                DB::table('integration_inbox')->where('id', $inboxId)->update(['status' => 'processed', 'error' => null, 'processed_at' => now()]);

                return $result;
            } catch (Throwable $e) {
                DB::table('integration_inbox')->where('id', $inboxId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'processed_at' => now()]);

                return null;
            }
        });
    }
}
