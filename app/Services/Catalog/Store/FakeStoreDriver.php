<?php

namespace App\Services\Catalog\Store;

use Illuminate\Support\Facades\Log;

/** Accepts every update and only logs it. Used in testing, staging and local. */
class FakeStoreDriver implements StoreDriver
{
    /** @var list<array> what would have been sent (tests read this) */
    public static array $sent = [];

    public function name(): string
    {
        return 'fake';
    }

    public function pushVariant(object $link, array $payload): void
    {
        self::$sent[] = ['external_product_id' => $link->external_product_id, 'external_variant_id' => $link->external_variant_id] + $payload;
        Log::info('[fake store] '.json_encode(end(self::$sent)));
    }
}
