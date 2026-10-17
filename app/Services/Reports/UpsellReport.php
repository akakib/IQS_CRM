<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Upsell: on a website order (what the customer chose there is known), while it is being verified by call (before
 * Confirmed), the person on the call added an item or more of one and the total went up; it counts for whoever made
 * the edit. Entry or price fixes are not upsells. Read from order_amendments (every edit is kept): who,
 * which items, how much more. It counts as earned once the order is delivered (fully or partly); cancelled or
 * returned is lost; anything else is still on its way.
 */
class UpsellReport
{
    /** @return Collection<int, object> one row per upsell edit, newest first */
    public function rows(string $from, string $to, ?int $userId = null): Collection
    {
        $fixes = DB::table('status_reasons')->where('reason_type', 'amendment')->whereIn('system_key', ['entry_error', 'price_error'])->pluck('id')->all() ?: [0];
        $won = OrderStatus::idsFor(['delivered', 'partial_delivered']);
        $lost = OrderStatus::idsFor(['cancelled', 'returned']);

        return DB::table('order_amendments as a')->join('orders as o', 'o.id', '=', 'a.order_id')->join('users as u', 'u.id', '=', 'a.requested_by')
            ->where('o.channel', 'web')->whereNotNull('a.applied_at')->where('a.amount_diff', '>', 0)->whereNotIn('a.reason_id', $fixes)
            ->whereIn('a.status_at_time_id', OrderStatus::idsFor(['new', 'record_verified', 'no_answer'])) // during the verifying call
            ->when($userId, fn ($q, $id) => $q->where('a.requested_by', $id))
            ->whereBetween('a.applied_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderByDesc('a.applied_at')
            ->get(['a.id', 'a.order_id', 'a.amount_diff', 'a.changes', 'a.applied_at', 'a.requested_by', 'u.name', 'u.photo_path', 'o.order_no', 'o.ship_name', 'o.status_id'])
            ->map(function ($r) use ($won, $lost) {
                $added = collect(json_decode((string) $r->changes, true) ?: [])->filter(fn ($c) => $c['type'] === 'added' || ($c['type'] === 'qty' && (float) $c['to'] > (float) $c['from']));
                $r->added = $added->map(fn ($c) => $c['type'] === 'added' ? $c['item'].' ×'.($c['to'] + 0) : $c['item'].' +'.((float) $c['to'] - (float) $c['from']))->values()->all();
                $r->state = in_array($r->status_id, $won, true) ? 'won' : (in_array($r->status_id, $lost, true) ? 'lost' : 'open');

                return $r;
            })
            ->filter(fn ($r) => $r->added !== []) // a price change alone is not an upsell
            ->values();
    }

    /** Per person: orders, extra money earned (delivered), still on the way, lost. Most earned first. */
    public function people(Collection $rows): Collection
    {
        return $rows->groupBy('requested_by')->map(fn ($g) => (object) [
            'id' => $g->first()->requested_by, 'name' => $g->first()->name, 'photo' => $g->first()->photo_path,
            'orders' => $g->pluck('order_id')->unique()->count(),
            'won' => (float) $g->where('state', 'won')->sum('amount_diff'),
            'open' => (float) $g->where('state', 'open')->sum('amount_diff'),
            'lost' => (float) $g->where('state', 'lost')->sum('amount_diff'),
            'rows' => $g->values(),
        ])->sortByDesc(fn ($p) => [$p->won, $p->open])->values();
    }
}
