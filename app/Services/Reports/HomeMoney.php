<?php

namespace App\Services\Reports;

use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The money part of the Dashboard home (Owner and Manager): sales, what it cost, profit, cash, and when orders come in.
 * Every number is read from the same place as its report (Sales report, Order P&L, COD payouts), so they agree.
 * Seven grouped queries whatever the range.
 */
class HomeMoney
{
    public function __construct(private SalesReport $sales, private OrderProfit $profit) {}

    public function build(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $step = fn (string $stage) => DB::table('orders as o')->joinSub($this->sales->stage($stage, $from, $to), 's', 's.order_id', '=', 'o.id')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(o.grand_total), 0) as money')->first();

        // Delivered, the courier has the cash, and it is not in a payout statement yet (as Payments > COD counts it).
        $atCourier = DB::table('shipments')->whereNotNull('final_at')->where('collected_amount', '>', 0)->whereNull('payout_id')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(collected_amount - COALESCE(delivery_charge, 0)), 0) as money')->first();

        return [
            'confirmed' => $step('confirmed'),
            'delivered' => $step('delivered'),
            'pl' => $this->profit->totals($from, $to),
            'missingCost' => $this->profit->missingCost($from, $to),
            'atCourier' => $atCourier,
            'paidOut' => (float) DB::table('courier_payouts')->whereBetween('paid_on', [$from, $to])->sum(DB::raw('total_collected - total_charge')),
            'advance' => (float) DB::table('order_payments')->where('payment_type', 'advance')->where('status', 'verified')->whereBetween('verified_at', $range)->sum('amount'),
            ...$this->arrivals($from, $to, $range),
        ];
    }

    /** Orders that arrived in the range: per hour of the day (0 to 23) and per day (per month past two months). One query. */
    private function arrivals(string $from, string $to, array $range): array
    {
        $rows = DB::table('orders')->whereBetween('created_at', $range)
            ->selectRaw('substr(created_at, 1, 10) as d, substr(created_at, 12, 2) as h, COUNT(*) as n')->groupByRaw('substr(created_at, 1, 10), substr(created_at, 12, 2)')->get();

        $hours = array_fill(0, 24, 0);
        $byDay = [];
        foreach ($rows as $r) {
            $hours[(int) $r->h] += (int) $r->n;
            $byDay[$r->d] = ($byDay[$r->d] ?? 0) + (int) $r->n;
        }

        $monthly = CarbonPeriod::create($from, $to)->count() > 62;
        $days = [];
        $start = $monthly ? \Illuminate\Support\Carbon::parse($from)->startOfMonth() : $from;
        foreach (CarbonPeriod::create($start, $monthly ? '1 month' : '1 day', $to) as $d) {
            $key = $monthly ? $d->format('Y-m') : $d->toDateString();
            $days[$key] = ['label' => $monthly ? $d->format('M Y') : $d->format('d M'), 'n' => 0];
        }
        foreach ($byDay as $d => $n) {
            $key = $monthly ? substr($d, 0, 7) : $d;
            if (isset($days[$key])) {
                $days[$key]['n'] += $n;
            }
        }

        return ['hours' => $hours, 'days' => array_values($days), 'monthly' => $monthly, 'arrived' => array_sum($hours)];
    }
}
