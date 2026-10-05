<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Marketing\AdCostService;
use App\Services\Reports\RoasReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Marketing > Ad spend & ROAS: accounts, daily spend (pull, CSV, manual) and the ROAS table. */
class MarketingController extends Controller
{
    public function __construct(private AdCostService $costs, private ActivityLogger $logger) {}

    public function index(Request $request, RoasReport $roas): View
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $valid($request->query('from')) ? $request->query('from') : now()->subDays(13)->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return view('marketing.index', [
            'report' => $roas->build($from, $to),
            'from' => $from, 'to' => $to,
            'accounts' => DB::table('ad_accounts')->orderBy('platform')->orderBy('name')->get(),
            'balance' => [
                'bought' => (float) DB::table('usd_lots')->sum('usd'),
                'spent' => (float) DB::table('ad_spend_daily')->sum('spend_usd'),
                'remaining' => (float) DB::table('usd_lots')->sum('usd_remaining'),
                'unfunded' => (float) DB::table('ad_spend_daily')->sum('unfunded_usd'),
            ],
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['meta', 'google'])],
            'name' => ['required', 'string', 'max:120'],
            'external_id' => ['nullable', 'string', 'max:60'],
            'timezone' => ['required', 'timezone'],
        ]);
        $id = DB::table('ad_accounts')->insertGetId($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->logger->log('ad_account.created', ['ad_account', $id], null, $data);

        return back()->with('success', __('Ad account added.'));
    }

    public function toggleAccount(int $account): RedirectResponse
    {
        $row = DB::table('ad_accounts')->where('id', $account)->first();
        abort_unless($row, 404);
        DB::table('ad_accounts')->where('id', $account)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
        $this->logger->log('ad_account.updated', ['ad_account', $account], ['is_active' => (bool) $row->is_active], ['is_active' => ! $row->is_active]);

        return back()->with('success', __('Saved.'));
    }

    /** One day typed by hand (overwrites that account-day). */
    public function storeSpend(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ad_account_id' => ['required', 'integer', 'exists:ad_accounts,id'],
            'spend_date' => ['required', 'date', 'before_or_equal:today'],
            'spend_usd' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'messages' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'purchases' => ['nullable', 'integer', 'min:0'],
            'reported_value' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->costs->saveSpend((int) $data['ad_account_id'], $data['spend_date'], $data, 'manual');
        $this->costs->rebuild();
        $this->logger->log('ad_spend.saved', ['ad_account', $data['ad_account_id']], null, $data);

        return back()->with('success', __('Spend saved and costed from the dollar lots.'));
    }

    /**
     * CSV import (e.g. a Google Ads daily report). Needs a date column and a
     * spend column; others are optional. Header names are matched loosely.
     */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ad_account_id' => ['required', 'integer', 'exists:ad_accounts,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);
        $aliases = [
            'date' => ['date', 'day', 'reporting starts', 'date_start'],
            'spend_usd' => ['spend', 'cost', 'amount spent', 'amount spent (usd)', 'spend_usd'],
            'clicks' => ['clicks', 'link clicks'],
            'impressions' => ['impressions', 'impr.'],
            'messages' => ['messages', 'messaging conversations started'],
            'purchases' => ['purchases', 'conversions'],
            'reported_value' => ['purchase value', 'conv. value', 'purchases conversion value', 'reported_value'],
        ];

        $fh = fopen($data['file']->getRealPath(), 'r');
        $map = [];
        $saved = 0;
        $skipped = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if ($map === []) {
                $head = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF\"")), $row);
                foreach ($aliases as $field => $names) {
                    foreach ($names as $n) {
                        if (($i = array_search($n, $head, true)) !== false) {
                            $map[$field] = $i;
                            break;
                        }
                    }
                }
                if (! isset($map['date'], $map['spend_usd'])) {
                    fclose($fh);

                    return back()->with('error', __('The file needs a Date (or Day) column and a Spend (or Cost) column.'));
                }

                continue;
            }
            $date = strtotime((string) ($row[$map['date']] ?? ''));
            $spend = str_replace([',', '$'], '', (string) ($row[$map['spend_usd']] ?? ''));
            if (! $date || ! is_numeric($spend)) {
                $skipped++;

                continue;
            }
            $m = [];
            foreach ($map as $field => $i) {
                $m[$field] = str_replace([',', '$'], '', (string) ($row[$i] ?? '0'));
            }
            $this->costs->saveSpend((int) $data['ad_account_id'], date('Y-m-d', $date), $m, 'import');
            $saved++;
        }
        fclose($fh);
        $this->costs->rebuild();
        $this->logger->log('ad_spend.imported', ['ad_account', $data['ad_account_id']], null, ['days' => $saved, 'skipped' => $skipped]);

        return back()->with('success', __(':n day(s) imported, :s row(s) skipped.', ['n' => $saved, 's' => $skipped]));
    }

    public function pull(): RedirectResponse
    {
        $code = Artisan::call('ads:pull-spend');

        return back()->with($code === 0 ? 'success' : 'error', $code === 0 ? __('Spend pulled for the last 3 days.') : __('Some accounts failed. See the system log.'));
    }
}
