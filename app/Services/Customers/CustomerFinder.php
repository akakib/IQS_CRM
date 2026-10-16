<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customers a search is about, for the one search (Order search): a phone in any form (their own
 * numbers, or a number only an order carries), a courier CN (imported or booked in IQS), or a name.
 * Merged customers lead to the one they were merged into. Counts come from CustomerStats.
 */
class CustomerFinder
{
    public const COLUMNS = ['id', 'name', 'primary_phone', 'risk_level', 'orders_count', 'delivered_count', 'returned_count', 'created_at'];

    /** @return Collection<int, Customer> at most 10 */
    public function find(string $q, string $by = 'all'): Collection
    {
        $q = trim(Phone::englishDigits($q));
        if (mb_strlen($q) < 3) {
            return collect();
        }
        $phone = in_array($by, ['all', 'phone'], true) ? Phone::normalize($q) : null;
        if ($phone) {
            $id = DB::table('customer_phones')->where('phone', $phone)->value('customer_id')
                ?? Customer::where('primary_phone', $phone)->value('id')
                ?? Order::where('ship_phone', $phone)->orWhere('ship_alt_phone', $phone)->latest('id')->value('customer_id');

            return $this->one($id);
        }
        if (in_array($by, ['all', 'cn'], true) && preg_match('/\d{5,}/', $q) && ($cn = HistoryImport::cn($q))) {
            $id = DB::table('customer_past_orders')->where('cn', $cn)->value('customer_id')
                ?? DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
                    ->where(fn ($w) => $w->where('s.consignment_id', $cn)->orWhere('s.tracking_code', $cn))->value('o.customer_id');
            if ($id) {
                return $this->one($id);
            }
        }
        if (in_array($by, ['all', 'name'], true) && ! preg_match('/^\+?[\d\s\-]+$/', $q)) {
            return Customer::whereNull('merged_into_id')
                ->where(fn ($w) => $w->where('name', 'like', $q.'%')->orWhere('name', 'like', '% '.$q.'%'))
                ->orderByDesc('orders_count')->limit(10)->get(self::COLUMNS);
        }

        return collect();
    }

    /** The customer (or the one they were merged into); none when deleted for good. */
    private function one(?int $id): Collection
    {
        $c = $id ? Customer::withTrashed()->find($id) : null;
        for ($hop = 0; $c && $c->merged_into_id && $hop < 10; $hop++) {
            $c = Customer::withTrashed()->find($c->merged_into_id);
        }

        return $c && ! $c->trashed() ? collect([$c]) : collect();
    }
}
