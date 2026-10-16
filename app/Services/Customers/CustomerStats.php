<?php

namespace App\Services\Customers;

use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * A customer's counts, one rule for every screen, counted again here whenever orders change:
 *  - orders_count, delivered_count, returned_count: the whole picture (IQS orders and orders imported
 *    from a file), what people read on the customer page, journey, search, order page and desk;
 *  - iqs_orders_count, iqs_delivered_count, iqs_returned_count: only what IQS saw itself. Verification
 *    rules, the fraud check and "new customer" use these, so a typed-in file can never make a customer
 *    look trusted (imported counts are a separate rule condition an admin may choose to use).
 */
class CustomerStats
{
    /** @param  iterable<int|null>  $customerIds */
    public static function refresh(iterable $customerIds): void
    {
        $ids = collect($customerIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $delivered = OrderStatus::idsFor(['delivered', 'partial_delivered']);
        $returned = OrderStatus::idsFor(['returned']);
        foreach ($ids->chunk(500) as $chunk) {
            $own = DB::table('orders')->whereIn('customer_id', $chunk)->groupBy('customer_id')
                ->selectRaw('customer_id, COUNT(*) as n, SUM(CASE WHEN status_id IN ('.self::list($delivered).') THEN 1 ELSE 0 END) as d,
                    SUM(CASE WHEN status_id IN ('.self::list($returned).') THEN 1 ELSE 0 END) as r')
                ->get()->keyBy('customer_id');
            $past = DB::table('customer_past_orders')->whereIn('customer_id', $chunk)->groupBy('customer_id')
                ->selectRaw("customer_id, COUNT(*) as n, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as d, SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as r")
                ->get()->keyBy('customer_id');
            foreach ($chunk as $id) {
                $o = $own[$id] ?? null;
                $p = $past[$id] ?? null;
                DB::table('customers')->where('id', $id)->update([
                    'orders_count' => (int) ($o->n ?? 0) + (int) ($p->n ?? 0),
                    'delivered_count' => (int) ($o->d ?? 0) + (int) ($p->d ?? 0),
                    'returned_count' => (int) ($o->r ?? 0) + (int) ($p->r ?? 0),
                    'iqs_orders_count' => (int) ($o->n ?? 0),
                    'iqs_delivered_count' => (int) ($o->d ?? 0),
                    'iqs_returned_count' => (int) ($o->r ?? 0),
                ]);
            }
        }
    }

    /** Every customer with orders from this import (call before an Undo too, to catch those it takes away). */
    public static function ofImport(int $importId): array
    {
        return DB::table('customer_past_orders')->where('customer_import_id', $importId)->distinct()->pluck('customer_id')->all();
    }

    /** @param  list<int>  $ids */
    private static function list(array $ids): string
    {
        return $ids ? implode(',', array_map('intval', $ids)) : '0';
    }
}
