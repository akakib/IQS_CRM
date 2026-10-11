<?php

namespace App\Services\Marketing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campaign, ad set and ad results from a Meta Ads Manager export (CSV).
 * Columns are found by their header, so any column order and extra columns
 * work. With a "Day" column each row is one ad on one day, and the account's
 * daily spend can be filled from it too (the taka cost then follows the
 * dollar lots as usual).
 */
class AdInsights
{
    public const LEVELS = ['campaign' => 'Campaigns', 'adset' => 'Ad sets', 'ad' => 'Ads'];

    public function __construct(private AdCostService $costs) {}

    /** @return array{rows: int, skipped: int, days: int, unknown_accounts: list<string>} */
    public function import(string $path, ?int $accountId, bool $fillDailySpend, User $by): array
    {
        $fh = fopen($path, 'r');
        $col = $this->columns(fgetcsv($fh) ?: []);
        if ($col['account_id'] === null && ! $accountId) {
            fclose($fh);
            throw ValidationException::withMessages(['ad_account_id' => __('The file has no Account ID column: choose the ad account.')]);
        }
        $accounts = DB::table('ad_accounts')->get(['id', 'external_id'])
            ->mapWithKeys(fn ($a) => [preg_replace('/^act_/', '', (string) $a->external_id) => $a->id]);

        $out = ['rows' => 0, 'skipped' => 0, 'days' => 0, 'unknown_accounts' => []];
        $daily = [];
        DB::transaction(function () use ($fh, $col, $accountId, $accounts, $by, &$out, &$daily) {
            while (($row = fgetcsv($fh)) !== false) {
                $get = fn (string $k) => $col[$k] === null ? null : trim((string) ($row[$col[$k]] ?? ''));
                $campaign = $get('campaign_name');
                $start = $get('day') ?: $get('starts');
                if (! $campaign || ! $start || ! strtotime($start)) {
                    $out['skipped']++; // blank or the export's total line

                    continue;
                }
                $account = $accountId;
                if ($col['account_id'] !== null) {
                    $ext = preg_replace('/^act_/', '', (string) $get('account_id'));
                    $account = $accounts[$ext] ?? null;
                    if (! $account) {
                        $out['unknown_accounts'][$ext] = $ext;
                        $out['skipped']++;

                        continue;
                    }
                }
                $day = date('Y-m-d', strtotime($start));
                $end = $col['day'] === null && $get('ends') && strtotime($get('ends')) ? date('Y-m-d', strtotime($get('ends'))) : null;
                $ids = [$get('campaign_id'), $get('adset_id'), $get('ad_id')];
                $key = sha1(array_filter($ids) ? implode('|', $ids) : implode('|', [$campaign, $get('adset_name'), $get('ad_name')]));
                $m = [
                    'spend_usd' => $this->num($get('spend')), 'results' => $this->num($get('results')),
                    'impressions' => (int) $this->num($get('impressions')), 'reach' => (int) $this->num($get('reach')),
                    'clicks' => (int) $this->num($get('clicks')), 'messages' => (int) $this->num($get('messages')),
                    'purchases' => (int) $this->num($get('purchases')), 'purchase_value' => $this->num($get('value')),
                ];
                DB::table('ad_insights')->updateOrInsert(['ad_account_id' => $account, 'day' => $day, 'row_key' => $key], $m + [
                    'period_end' => $end && $end !== $day ? $end : null,
                    'campaign_id' => $ids[0] ?: null, 'campaign_name' => mb_substr($campaign, 0, 255),
                    'adset_id' => $ids[1] ?: null, 'adset_name' => $get('adset_name') ? mb_substr($get('adset_name'), 0, 255) : null,
                    'ad_id' => $ids[2] ?: null, 'ad_name' => $get('ad_name') ? mb_substr($get('ad_name'), 0, 255) : null,
                    'result_type' => $get('result_type') ? mb_substr($get('result_type'), 0, 120) : null,
                    'imported_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $out['rows']++;
                if ($col['day'] !== null) {
                    $d = &$daily[$account][$day];
                    foreach (['spend_usd', 'impressions', 'clicks', 'messages', 'purchases'] as $f) {
                        $d[$f] = ($d[$f] ?? 0) + $m[$f];
                    }
                    $d['reported_value'] = ($d['reported_value'] ?? 0) + $m['purchase_value'];
                    unset($d);
                }
            }
        });
        fclose($fh);

        if ($fillDailySpend && $daily) {
            foreach ($daily as $account => $days) {
                foreach ($days as $day => $m) {
                    $this->costs->saveSpend((int) $account, $day, $m, 'import');
                    $out['days']++;
                }
            }
            $this->costs->rebuild();
        }
        $out['unknown_accounts'] = array_values($out['unknown_accounts']);

        return $out;
    }

    /**
     * Rows for one level (campaign, ad set or ad) in a date range: spend in dollars and in
     * taka (the account's real cost that day; else the average dollar rate), results and the rest.
     */
    public function report(string $level, string $from, string $to, ?int $accountId): \Illuminate\Support\Collection
    {
        $avgRate = (float) (DB::table('usd_lots')->selectRaw('SUM(bdt_total) / NULLIF(SUM(usd), 0) as r')->value('r') ?? 0);
        $group = match ($level) {
            'adset' => ['i.campaign_name', 'i.adset_name'],
            'ad' => ['i.campaign_name', 'i.adset_name', 'i.ad_name'],
            default => ['i.campaign_name'],
        };

        return DB::table('ad_insights as i')
            ->leftJoin('ad_spend_daily as sd', fn ($j) => $j->on('sd.ad_account_id', '=', 'i.ad_account_id')->on('sd.spend_date', '=', 'i.day'))
            ->where('i.day', '>=', $from)->whereRaw('COALESCE(i.period_end, i.day) <= ?', [$to])
            ->when($accountId, fn ($q) => $q->where('i.ad_account_id', $accountId))
            ->groupBy($group)
            ->selectRaw(implode(', ', $group).", SUM(i.spend_usd) as spend_usd,
                SUM(i.spend_usd * COALESCE(sd.bdt_cost / NULLIF(sd.spend_usd, 0), ?)) as spend_bdt,
                SUM(i.results) as results, COUNT(DISTINCT i.result_type) as result_types, MAX(i.result_type) as result_type,
                SUM(i.impressions) as impressions, SUM(i.reach) as reach, SUM(i.clicks) as clicks, SUM(i.messages) as messages,
                SUM(i.purchases) as purchases, SUM(i.purchase_value) as purchase_value, COUNT(DISTINCT i.ad_account_id) as accounts", [$avgRate])
            ->orderByDesc('spend_usd')->get();
    }

    /** @return array<string, ?int> */
    private function columns(array $header): array
    {
        $head = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $find = function (array $names, bool $prefix = false) use ($head): ?int {
            foreach ($head as $i => $h) {
                foreach ($names as $n) {
                    if ($h === $n || ($prefix && str_starts_with($h, $n))) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $col = [
            'day' => $find(['day', 'date']), 'starts' => $find(['reporting starts']), 'ends' => $find(['reporting ends']),
            'account_id' => $find(['account id']),
            'campaign_name' => $find(['campaign name']), 'campaign_id' => $find(['campaign id']),
            'adset_name' => $find(['ad set name']), 'adset_id' => $find(['ad set id']),
            'ad_name' => $find(['ad name']), 'ad_id' => $find(['ad id']),
            'spend' => $find(['amount spent'], true), 'results' => $find(['results']), 'result_type' => $find(['result indicator', 'result type']),
            'impressions' => $find(['impressions']), 'reach' => $find(['reach']), 'clicks' => $find(['link clicks', 'clicks (all)', 'clicks']),
            'messages' => $find(['messaging conversations started', 'new messaging contacts']),
            'purchases' => $find(['purchases', 'website purchases']),
            'value' => $find(['purchases conversion value', 'purchase conversion value', 'website purchases conversion value']),
        ];
        if ($col['campaign_name'] === null || $col['spend'] === null || ($col['day'] ?? $col['starts']) === null) {
            throw ValidationException::withMessages(['file' => __('Export from Ads Manager with Campaign name, Amount spent and Day (or Reporting starts). Columns found: :c', ['c' => implode(', ', array_filter($header))])]);
        }

        return $col;
    }

    private function num(?string $v): float
    {
        return round((float) preg_replace('/[^\d.\-]/', '', (string) $v), 2);
    }
}
