<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\Points\PointHooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Points: admin rules (Settings > Points rules), each person's own ledger
 * with disputes, and the manager review of integrity flags, disputes and QA.
 */
class PointsController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    // Rules

    public function rules(Request $request): View
    {
        return view('points.rules', [
            'rules' => DB::table('point_rules')->orderBy('sort_order')->orderBy('id')->get()->groupBy('trigger_key'),
            'conditions' => DB::table('point_rule_conditions')->get()->groupBy('rule_id'),
            'triggers' => config('points.triggers'),
            'fields' => config('points.fields'),
            'test' => $request->session()->get('points_test'),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trigger_key' => ['required', Rule::in(array_keys(config('points.triggers')))],
            'name' => ['required', 'string', 'max:150'],
            'points' => ['required', 'numeric', 'between:-1000,1000', 'not_in:0'],
            'recipient' => ['required', Rule::in(['order_owner', 'actor', 'packer', 'previous_owner'])],
            'settle_on' => ['required', Rule::in(['immediate', 'order_final'])],
            'requires_delivery' => ['boolean'],
            'conditions' => ['array', 'max:6'],
            'conditions.*.field' => ['required', Rule::in(array_keys(config('points.fields')))],
            'conditions.*.operator' => ['required', Rule::in(['>=', '<=', '=', '!=', '>', '<'])],
            'conditions.*.value' => ['required', 'string', 'max:50'],
        ]);

        DB::transaction(function () use ($data) {
            $id = DB::table('point_rules')->insertGetId([
                'trigger_key' => $data['trigger_key'], 'name' => $data['name'], 'points' => $data['points'],
                'recipient' => $data['recipient'], 'settle_on' => $data['settle_on'], 'requires_delivery' => ! empty($data['requires_delivery']),
                'is_active' => true, 'sort_order' => (int) DB::table('point_rules')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['conditions'] ?? [] as $c) {
                DB::table('point_rule_conditions')->insert(['rule_id' => $id, 'field' => $c['field'], 'operator' => $c['operator'], 'value' => $c['value']]);
            }
            $this->logger->log('point_rule.created', ['point_rule', $id], null, $data);
        });

        return back()->with('success', __('Rule added.'));
    }

    /** Points value and on/off. Applies to new awards only; the ledger keeps a snapshot. */
    public function updateRule(Request $request, int $rule): RedirectResponse
    {
        $row = DB::table('point_rules')->where('id', $rule)->first();
        abort_unless($row, 404);
        $data = $request->validate([
            'points' => ['required', 'numeric', 'between:-1000,1000', 'not_in:0'],
            'is_active' => ['boolean'],
        ]);
        $after = ['points' => (float) $data['points'], 'is_active' => ! empty($data['is_active'])];
        DB::table('point_rules')->where('id', $rule)->update($after + ['updated_at' => now()]);
        $this->logger->log('point_rule.updated', ['point_rule', $rule], ['points' => (float) $row->points, 'is_active' => (bool) $row->is_active], $after);

        return back()->with('success', __('Saved. New points use this value; earlier ones stay as they were.'));
    }

    public function test(Request $request, PointHooks $hooks): RedirectResponse
    {
        $data = $request->validate(['order_no' => ['required', 'string']]);
        $order = Order::where('order_no', strtoupper(trim($data['order_no'])))->first();
        if (! $order) {
            return back()->with('error', __('No order with that number.'));
        }

        return back()->with('points_test', [
            'order_no' => $order->order_no,
            'preview' => collect($hooks->preview($order))->map(fn ($p) => [
                'context' => $p['context'],
                'rules' => $p['rules']->map(fn ($r) => ['name' => $r->name, 'points' => (float) $r->points, 'recipient' => $r->recipient])->all(),
            ])->all(),
            'ledger' => DB::table('point_ledger as l')->join('users as u', 'u.id', '=', 'l.user_id')->where('l.order_id', $order->id)
                ->orderBy('l.id')->get(['u.name', 'l.points', 'l.status', 'l.rule_snapshot'])
                ->map(fn ($l) => ['name' => $l->name, 'points' => (float) $l->points, 'status' => $l->status, 'rule' => json_decode($l->rule_snapshot, true)['name'] ?? ''])->all(),
        ]);
    }

    // My points

    public function mine(Request $request): View
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $base = DB::table('point_ledger')->where('point_ledger.user_id', $request->user()->id)
            ->whereBetween('point_ledger.created_at', [$start, $start->copy()->endOfMonth()]);

        return view('points.mine', [
            'month' => $month,
            'totals' => (clone $base)->selectRaw('status, SUM(points) as total, COUNT(*) as n')->groupBy('status')->get()->keyBy('status'),
            'entries' => (clone $base)->leftJoin('orders as o', 'o.id', '=', 'point_ledger.order_id')
                ->orderByDesc('point_ledger.id')->select('point_ledger.*', 'o.order_no')->paginate($perPage)->withQueryString(),
            'perPage' => $perPage,
            'disputeDays' => (int) settings('points.dispute_days'),
            'trial' => (bool) settings('points.trial_mode'),
        ]);
    }

    public function dispute(Request $request, int $entry, NotificationService $notifications): RedirectResponse
    {
        $row = DB::table('point_ledger')->where('id', $entry)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        if (! self::canDispute($row, (int) settings('points.dispute_days'))) {
            return back()->with('error', __('This point cannot be disputed.'));
        }
        DB::table('point_ledger')->where('id', $entry)->update(['disputed_at' => now(), 'dispute_note' => $data['note'], 'dispute_status' => 'open', 'updated_at' => now()]);
        $notifications->send('points_review', __(':n disputed a point', ['n' => $request->user()->name]), $data['note'], ['link' => route('points.review', ['tab' => 'disputes'])]);

        return back()->with('success', __('Sent to a manager.'));
    }

    /** Only minus points, once, within the dispute window. */
    public static function canDispute(object $row, int $days): bool
    {
        return ! $row->disputed_at && $row->status !== 'revoked' && (float) $row->points < 0
            && now()->lessThanOrEqualTo(Carbon::parse($row->created_at)->addDays($days));
    }

    // Review (managers)

    public function review(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['flags', 'disputes', 'qa'], true) ? $request->query('tab') : 'flags';

        $data = ['tab' => $tab, 'counts' => [
            'flags' => DB::table('integrity_flags')->where('status', 'open')->count(),
            'disputes' => DB::table('point_ledger')->where('dispute_status', 'open')->count(),
        ]];

        if ($tab === 'flags') {
            $data['flags'] = DB::table('integrity_flags as f')->join('users as u', 'u.id', '=', 'f.user_id')->leftJoin('orders as o', 'o.id', '=', 'f.order_id')
                ->where('f.status', 'open')->orderByDesc('f.id')->select('f.*', 'u.name', 'o.order_no')->paginate(25)->withQueryString();
        } elseif ($tab === 'disputes') {
            $data['disputes'] = DB::table('point_ledger as l')->join('users as u', 'u.id', '=', 'l.user_id')->leftJoin('orders as o', 'o.id', '=', 'l.order_id')
                ->where('l.dispute_status', 'open')->orderBy('l.disputed_at')->select('l.*', 'u.name', 'o.order_no')->paginate(25)->withQueryString();
        } else {
            $data['sample'] = $this->qaSample();
        }

        return view('points.review', $data);
    }

    /** Confirm = it really was a fake status: the big minus is applied. Dismiss = no action. */
    public function decideFlag(Request $request, int $flag, PointHooks $hooks): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['confirmed', 'dismissed'])], 'note' => ['nullable', 'string', 'max:500']]);
        $row = DB::table('integrity_flags')->where('id', $flag)->where('status', 'open')->first();
        abort_unless($row, 404);
        abort_if((int) $row->user_id === $request->user()->id, 403, __('You cannot review your own flag.'));

        DB::transaction(function () use ($row, $data, $request, $hooks) {
            DB::table('integrity_flags')->where('id', $row->id)->update([
                'status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null, 'updated_at' => now(),
            ]);
            if ($data['decision'] === 'confirmed') {
                $hooks->fakeStatus($row->order_id ? Order::find($row->order_id) : null, (int) $row->user_id);
            }
            $this->logger->log('integrity_flag.'.$data['decision'], ['integrity_flag', $row->id], null, $data);
        });

        return back()->with('success', $data['decision'] === 'confirmed' ? __('Confirmed. The fake-status minus was added.') : __('Dismissed.'));
    }

    public function decideDispute(Request $request, int $entry): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['upheld', 'rejected'])]]);
        $row = DB::table('point_ledger')->where('id', $entry)->where('dispute_status', 'open')->first();
        abort_unless($row, 404);
        abort_if((int) $row->user_id === $request->user()->id, 403, __('You cannot decide your own dispute.'));

        $update = ['dispute_status' => $data['decision'], 'dispute_resolved_by' => $request->user()->id, 'updated_at' => now()];
        if ($data['decision'] === 'upheld') {
            $update += ['status' => 'revoked', 'revoke_reason' => __('Dispute upheld'), 'revoked_at' => now()];
        }
        DB::table('point_ledger')->where('id', $entry)->update($update);
        $this->logger->log('point_dispute.'.$data['decision'], ['point_ledger', $entry], null, $data);

        return back()->with('success', $data['decision'] === 'upheld' ? __('Upheld: the point was removed.') : __('Rejected: the point stays.'));
    }

    public function storeQa(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['result' => ['required', Rule::in(['call_verified', 'not_called', 'wrong_info'])], 'note' => ['nullable', 'string', 'max:500']]);
        abort_unless($order->owner_id, 422);
        if (DB::table('qa_reviews')->where('order_id', $order->id)->exists()) {
            return back()->with('error', __('This order was already checked.'));
        }

        DB::transaction(function () use ($order, $data, $request) {
            DB::table('qa_reviews')->insert([
                'order_id' => $order->id, 'agent_id' => $order->owner_id, 'reviewer_id' => $request->user()->id,
                'result' => $data['result'], 'note' => $data['note'] ?? null, 'created_at' => now(),
            ]);
            // The customer says nobody called: a manager still confirms it on the flags tab.
            if ($data['result'] !== 'call_verified') {
                DB::table('integrity_flags')->insert([
                    'order_id' => $order->id, 'user_id' => $order->owner_id, 'flag_type' => 'qa_'.$data['result'], 'detected_by' => 'qa',
                    'details' => $data['note'] ?? __('Call-back check failed.'), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', __('Saved.'));
    }

    /**
     * Weekly call-back sample: up to 5 orders per agent confirmed by a person
     * in the last 7 days and not checked yet. Ordered by a hash seeded with
     * the week, so the list stays the same all week.
     */
    private function qaSample(): Collection
    {
        $rows = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')->join('users as u', 'u.id', '=', 'o.owner_id')
            ->where('e.to_status_id', OrderStatus::idFor('confirmed'))->where('e.source', 'user')
            ->where('e.created_at', '>=', now()->subDays(7))
            ->whereNotExists(fn ($q) => $q->from('qa_reviews as q')->whereColumn('q.order_id', 'o.id'))
            ->orderByDesc('e.id')->limit(2000)
            ->get(['o.id', 'o.order_no', 'o.owner_id', 'u.name as agent', 'o.ship_name', 'o.ship_phone', 'o.grand_total', 'e.created_at as confirmed_at'])
            ->unique('id');

        $seed = now()->format('oW');

        return $rows->groupBy('owner_id')->flatMap(fn ($g) => $g->sortBy(fn ($r) => crc32($seed.'-'.$r->id))->take(5))->values();
    }
}
