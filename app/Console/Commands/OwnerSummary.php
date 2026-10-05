<?php

namespace App\Console\Commands;

use App\Models\OrderStatus;
use App\Services\Reports\OrderProfit;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Nightly numbers for the owner on Telegram: orders, outcomes, profit before ads, open problems. */
class OwnerSummary extends Command
{
    protected $signature = 'reports:owner-summary {--date= : Day to report (default today)}';

    protected $description = 'Send the nightly owner summary on Telegram';

    public function handle(TelegramService $telegram, OrderProfit $profit): int
    {
        $day = $this->option('date') ?: today()->toDateString();
        $range = [$day.' 00:00:00', $day.' 23:59:59'];
        $count = fn (string $key) => DB::table('order_events')->where('to_status_id', OrderStatus::idFor($key))->whereBetween('created_at', $range)->count();
        $t = $profit->totals($day, $day);
        $tk = fn ($v) => '৳'.number_format((float) $v);

        $text = '📊 <b>'.__('Iqbal Store, :d', ['d' => date('d M', strtotime($day))])."</b>\n"
            .__('New orders: :n', ['n' => DB::table('orders')->whereBetween('created_at', $range)->count()])."\n"
            .__('Confirmed: :c · Cancelled: :x', ['c' => $count('confirmed'), 'x' => $count('cancelled')])."\n"
            .__('Delivered: :d · Returned: :r', ['d' => $t['delivered'], 'r' => $t['orders'] - $t['delivered']])."\n"
            .__('Revenue: :r', ['r' => $tk($t['revenue'])])."\n"
            .__('Ad cost: :a', ['a' => $tk((float) DB::table('ad_spend_daily')->where('spend_date', $day)->sum('bdt_cost'))])."\n"
            .__('Profit after ads: :p', ['p' => $tk($t['profit'])])."\n"
            .__('Still waiting to be taken: :n', ['n' => DB::table('orders')->whereNull('moderator_id')->where('status_id', OrderStatus::idFor('new'))->count()]);

        $issues = DB::table('delivery_issues')->whereNull('resolved_at')->count();
        $flags = DB::table('integrity_flags')->where('status', 'open')->count();
        if ($issues || $flags) {
            $text .= "\n⚠️ ".__('Open delivery issues: :i · Points flags to review: :f', ['i' => $issues, 'f' => $flags]);
        }

        // Per moderator: delivered count and rate, orders that timed out, break minutes.
        $kpi = app(\App\Services\Reports\KpiScorecard::class)->build($day, $day)['rows'];
        $breaks = DB::table('staff_breaks')->whereBetween('started_at', $range)->where('counts_as_break', true)
            ->groupBy('user_id')->selectRaw('user_id, SUM(COALESCE(minutes, 0)) as minutes')->pluck('minutes', 'user_id');
        $names = $breaks->isEmpty() ? collect() : DB::table('users')->whereIn('id', $breaks->keys())->pluck('name', 'id');
        if ($kpi) {
            $text .= "\n\n<b>".__('Team')."</b>";
            foreach (array_slice($kpi, 0, 15) as $r) {
                $text .= "\n".__(':n: :d delivered:rate:t', [
                    'n' => $r['name'], 'd' => $r['delivered'],
                    'rate' => $r['delivery_rate'] !== null ? ' ('.$r['delivery_rate'].'%)' : '',
                    't' => $r['released'] ? ' · '.__(':k timed out', ['k' => $r['released']]) : '',
                ]);
            }
        }
        if ($breaks->isNotEmpty()) {
            $limit = (int) settings('work.break_limit_minutes');
            $text .= "\n\n<b>".__('Breaks')."</b>\n".$breaks->map(fn ($m, $id) => ($names[$id] ?? '#'.$id).' '.(int) $m.'m'.($limit > 0 && $m > $limit ? ' ⚠️' : ''))->join(' · ');
        }

        $telegram->send(config('services.telegram.owner_chat_id'), $text);
        $this->info('Sent.');

        return self::SUCCESS;
    }
}
