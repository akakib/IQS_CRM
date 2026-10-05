<?php

namespace App\Services\Marketing;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * USD lots, vendor dues and the FIFO cost of ad spend.
 *
 * FIFO is rebuilt in full whenever a lot or a day's spend changes: one row
 * per account per day keeps that cheap (hundreds of rows a year), and a full
 * rebuild can never drift the way patching earlier days could.
 */
class AdCostService
{
    public function __construct(private ActivityLogger $logger) {}

    public function addLot(array $d, User $by): int
    {
        return DB::transaction(function () use ($d, $by) {
            $bdt = round((float) $d['usd'] * (float) $d['rate'], 2);
            $paid = min($bdt, (float) ($d['paid_bdt'] ?? 0));
            if ($paid < $bdt && empty($d['due_date'])) {
                throw ValidationException::withMessages(['due_date' => __('Not fully paid: set the date the rest is due.')]);
            }
            $id = DB::table('usd_lots')->insertGetId([
                'vendor_id' => $d['vendor_id'], 'purchased_on' => $d['purchased_on'], 'usd' => $d['usd'], 'rate' => $d['rate'],
                'bdt_total' => $bdt, 'usd_remaining' => $d['usd'], 'due_date' => $paid < $bdt ? $d['due_date'] : null,
                'note' => $d['note'] ?? null, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($paid > 0) {
                $this->pay((int) $d['vendor_id'], ['amount_bdt' => $paid, 'paid_on' => $d['purchased_on'], 'lot_id' => $id,
                    'payment_method_id' => $d['payment_method_id'] ?? null, 'transaction_ref' => $d['transaction_ref'] ?? null], $by);
            }
            $this->logger->log('usd_lot.created', ['usd_lot', $id], null, $d + ['bdt_total' => $bdt]);
            $this->rebuild();

            return $id;
        });
    }

    public function pay(int $vendorId, array $d, User $by): void
    {
        DB::table('vendor_payments')->insert([
            'vendor_id' => $vendorId, 'lot_id' => $d['lot_id'] ?? null, 'amount_bdt' => $d['amount_bdt'], 'paid_on' => $d['paid_on'],
            'payment_method_id' => $d['payment_method_id'] ?? null, 'transaction_ref' => $d['transaction_ref'] ?? null,
            'note' => $d['note'] ?? null, 'created_by' => $by->id, 'created_at' => now(),
        ]);
        $this->logger->log('vendor_payment.created', ['ad_vendor', $vendorId], null, $d);
    }

    /** @return \Illuminate\Support\Collection<int, object> vendors with bought, paid and due (BDT) */
    public function vendorBalances(): \Illuminate\Support\Collection
    {
        $bought = DB::table('usd_lots')->groupBy('vendor_id')->selectRaw('vendor_id, SUM(bdt_total) as t, MIN(due_date) as next_due')->get()->keyBy('vendor_id');
        $paid = DB::table('vendor_payments')->groupBy('vendor_id')->selectRaw('vendor_id, SUM(amount_bdt) as t')->pluck('t', 'vendor_id');

        return DB::table('ad_vendors')->orderBy('name')->get()->map(function ($v) use ($bought, $paid) {
            $v->bought = (float) ($bought[$v->id]->t ?? 0);
            $v->paid = (float) ($paid[$v->id] ?? 0);
            $v->due = round($v->bought - $v->paid, 2);
            $v->next_due = $v->due > 0 ? ($bought[$v->id]->next_due ?? null) : null;

            return $v;
        });
    }

    /** Save one account-day (re-pulls overwrite it). */
    public function saveSpend(int $accountId, string $date, array $m, string $source): void
    {
        DB::table('ad_spend_daily')->updateOrInsert(['ad_account_id' => $accountId, 'spend_date' => $date], [
            'spend_usd' => round((float) ($m['spend_usd'] ?? 0), 2),
            'impressions' => (int) ($m['impressions'] ?? 0), 'clicks' => (int) ($m['clicks'] ?? 0),
            'messages' => (int) ($m['messages'] ?? 0), 'purchases' => (int) ($m['purchases'] ?? 0),
            'reported_value' => round((float) ($m['reported_value'] ?? 0), 2),
            'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Re-run FIFO over every lot and every day, then refresh the per-day order share. */
    public function rebuild(): void
    {
        DB::transaction(function () {
            DB::table('usd_lot_allocations')->delete();
            DB::table('usd_lots')->update(['usd_remaining' => DB::raw('usd')]);

            $lots = DB::table('usd_lots')->orderBy('purchased_on')->orderBy('id')->get(['id', 'usd', 'rate'])
                ->map(fn ($l) => (object) ['id' => $l->id, 'left' => (float) $l->usd, 'rate' => (float) $l->rate])->all();
            $i = 0;
            $lastRate = (float) settings('marketing.fallback_rate');
            $rows = [];

            foreach (DB::table('ad_spend_daily')->orderBy('spend_date')->orderBy('id')->get(['id', 'spend_usd']) as $s) {
                $need = (float) $s->spend_usd;
                $bdt = 0.0;
                while ($need > 0.004 && $i < count($lots)) {
                    $take = min($need, $lots[$i]->left);
                    if ($take > 0) {
                        $rows[] = ['spend_id' => $s->id, 'lot_id' => $lots[$i]->id, 'usd' => round($take, 2), 'bdt' => round($take * $lots[$i]->rate, 2)];
                        $bdt += $take * $lots[$i]->rate;
                        $lots[$i]->left -= $take;
                        $need -= $take;
                        $lastRate = $lots[$i]->rate;
                    }
                    if ($lots[$i]->left <= 0.004) {
                        $i++;
                    }
                }
                // No dollars left: cost it at the latest rate and show it as unfunded.
                $unfunded = $need > 0.004 ? round($need, 2) : 0;
                $bdt += $unfunded * $lastRate;
                DB::table('ad_spend_daily')->where('id', $s->id)->update(['bdt_cost' => round($bdt, 2), 'unfunded_usd' => $unfunded]);
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('usd_lot_allocations')->insert($chunk);
            }
            foreach ($lots as $l) {
                DB::table('usd_lots')->where('id', $l->id)->update(['usd_remaining' => round(max(0, $l->left), 2)]);
            }
        });

        $this->refreshCostDays();
    }

    /**
     * Each day's ad cost shared equally over the orders placed that day
     * (B2B excluded: ads do not bring them). Days without orders keep
     * their cost visible here but add nothing to any order.
     */
    public function refreshCostDays(?string $from = null): void
    {
        $costs = DB::table('ad_spend_daily')->when($from, fn ($q) => $q->where('spend_date', '>=', $from))
            ->groupBy('spend_date')->selectRaw('spend_date, SUM(bdt_cost) as c')->pluck('c', 'spend_date');
        if ($costs->isEmpty()) {
            return;
        }
        $days = $costs->keys()->map(fn ($d) => substr((string) $d, 0, 10));
        $orders = DB::table('orders')->where('channel', '!=', 'b2b')
            ->whereBetween('created_at', [$days->min().' 00:00:00', $days->max().' 23:59:59'])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupByRaw('DATE(created_at)')->pluck('n', 'd');

        foreach ($costs as $day => $cost) {
            $day = substr((string) $day, 0, 10);
            $n = (int) ($orders[$day] ?? 0);
            DB::table('ad_cost_days')->updateOrInsert(['day' => $day], [
                'bdt_cost' => round((float) $cost, 2), 'orders' => $n, 'per_order' => $n ? round((float) $cost / $n, 2) : 0, 'updated_at' => now(),
            ]);
        }
    }
}
