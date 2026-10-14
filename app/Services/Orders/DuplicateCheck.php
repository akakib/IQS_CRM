<?php

namespace App\Services\Orders;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How a customer's other order compares with this one, so staff see at a
 * glance whether it is a real duplicate: items the same, partly the same or
 * different, and the delivery address the same or different.
 */
class DuplicateCheck
{
    /**
     * @param  array<int, float>  $items  variant id => qty of the order being checked
     * @param  list<int>  $otherIds  the other orders
     * @return Collection<int, array{items: string, address: string}> other order id => comparison
     */
    public function compare(array $items, string $address, array $otherIds): Collection
    {
        if (! $otherIds) {
            return collect();
        }
        $theirItems = DB::table('order_items')->whereIn('order_id', $otherIds)->whereNotNull('variant_id')
            ->get(['order_id', 'variant_id', 'qty'])->groupBy('order_id');
        $theirAddress = DB::table('orders')->whereIn('id', $otherIds)->get(['id', 'ship_address', 'ship_thana'])->keyBy('id');

        return collect($otherIds)->mapWithKeys(function ($id) use ($items, $address, $theirItems, $theirAddress) {
            $theirs = $theirItems->get($id, collect())->groupBy('variant_id')->map(fn ($r) => round((float) $r->sum('qty'), 3))->all();
            $mine = array_map(fn ($q) => round((float) $q, 3), $items);
            ksort($theirs);
            ksort($mine);
            $common = array_intersect_key($mine, $theirs);
            $match = match (true) {
                $mine !== [] && $mine == $theirs => 'same',
                $common !== [] => 'some',
                default => 'other',
            };
            $other = $theirAddress[$id] ?? null;

            return [$id => [
                'items' => $match,
                'address' => $other && self::normal($other->ship_address.' '.$other->ship_thana) === self::normal($address) ? 'same' : 'other',
            ]];
        });
    }

    /** Address text compared without case, spaces or punctuation. */
    public static function normal(?string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $text));
    }

    /** The words staff read: "same items, same address". */
    public static function label(?string $items, ?string $address): string
    {
        $i = ['same' => __('same items'), 'some' => __('some same items'), 'other' => __('different items')][$items] ?? null;
        $a = ['same' => __('same address'), 'other' => __('different address')][$address] ?? null;

        return collect([$i, $a])->filter()->join(', ');
    }
}
