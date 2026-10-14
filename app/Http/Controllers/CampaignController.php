<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Marketing\AdInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Marketing > Campaigns: spend and results by campaign, ad set and ad (Ads Manager CSV for now). */
class CampaignController extends Controller
{
    public function index(Request $request, AdInsights $insights): View
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $valid($request->query('from')) ? $request->query('from') : now()->subDays(29)->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $level = array_key_exists((string) $request->query('level'), AdInsights::LEVELS) ? $request->query('level') : 'campaign';
        $account = $request->integer('account') ?: null;
        $rows = $insights->report($level, $from, $to, $account);

        return view('marketing.campaigns', [
            'rows' => $rows, 'level' => $level, 'from' => $from, 'to' => $to, 'account' => $account,
            'accounts' => DB::table('ad_accounts')->orderBy('name')->pluck('name', 'id')->all(),
            'totals' => ['spend_usd' => $rows->sum('spend_usd'), 'spend_bdt' => $rows->sum('spend_bdt'), 'messages' => $rows->sum('messages'),
                'purchases' => $rows->sum('purchases'), 'purchase_value' => $rows->sum('purchase_value')],
            'lastImport' => DB::table('ad_insights')->max('updated_at'),
        ]);
    }

    public function import(Request $request, AdInsights $insights, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'ad_account_id' => ['nullable', 'integer', 'exists:ad_accounts,id'],
        ]);
        $r = $insights->import($request->file('file')->getRealPath(), $data['ad_account_id'] ?? null, $request->boolean('fill_daily'), $request->user());
        $logger->log('ad_insights.imported', ['ad_account', (int) ($data['ad_account_id'] ?? 0)], null, $r);

        $message = __(':n rows imported, :s skipped.', ['n' => $r['rows'], 's' => $r['skipped']])
            .($r['days'] ? ' '.__('Daily spend filled for :d account-days.', ['d' => $r['days']]) : '')
            .($r['unknown_accounts'] ? ' '.__('Unknown ad account ids (add them first): :ids', ['ids' => implode(', ', $r['unknown_accounts'])]) : '')
            .($r['overlaps'] ? ' '.__(':n rows skipped: those ads are already in for those days in the other form (daily or a whole period). Use one form per period.', ['n' => $r['overlaps']]) : '');

        return back()->with($r['unknown_accounts'] || $r['overlaps'] ? 'error' : 'success', $message);
    }
}
