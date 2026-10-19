<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Orders\DeliveryIssueService;
use App\Services\Work\RiderCallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Rider hotline: find a parcel fast, solve small things here, hand big ones to the assigned moderator. */
class HotlineController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $results = collect();

        if ($q !== '') {
            $results = app(RiderCallService::class)->search($q)
                ->select(['orders.id', 'order_no', 'status_id', 'moderator_id', 'ship_name', 'ship_phone', 'cod_amount', 'created_at', 'packed_version', 'current_version', 'edited_after_pack', 'is_duplicate_flag', 'duplicate_of_no', 'duplicate_items', 'duplicate_address'])
                ->with('moderator:id,name')->limit(10)->get();
        }

        // The whole order (the list above loads only a few columns): its parcel, address and items.
        $pick = $results->count() === 1 ? $results->first()->id : $request->integer('order');
        $selected = $pick ? Order::find($pick) : null;
        $detail = null;
        if ($selected) {
            $selected->load(['items:id,order_id,name_snapshot,qty,unit', 'moderator:id,name']);
            $detail = [
                'order' => $selected,
                'shipment' => DB::table('shipments')->where('id', $selected->active_shipment_id)->first(['consignment_id', 'courier_status', 'cod_amount']),
                'notes' => DB::table('order_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')->where('n.order_id', $selected->id)
                    ->orderByDesc('n.id')->limit(20)->get(['n.body', 'n.note_type', 'n.created_at', 'u.name as user']),
                'issues' => DB::table('delivery_issues')->where('order_id', $selected->id)->whereNull('resolved_at')->get(),
            ];
        }

        return view('hotline.index', ['q' => $q, 'results' => $results, 'detail' => $detail, 'statuses' => OrderStatus::map()]);
    }

    /** Small issue solved on the call: a rider call like the one from Communication (checked with the customer, Riders report, work time, timeline). */
    public function solved(Order $order, Request $request, RiderCallService $calls): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500'], 'rider_phone' => ['nullable', 'string', 'max:20'],
            'verdict' => ['required', Rule::in(array_keys(RiderCallService::VERDICTS))]], ['verdict.required' => __('Say what the customer said.')]);
        $calls->record($request->user(), ['order_id' => $order->id, 'rider_phone' => $data['rider_phone'] ?? null, 'claim' => 'other', 'verdict' => $data['verdict'], 'action' => 'solved', 'note' => $data['note']]);

        return back()->with('success', __('Rider call saved.'));
    }

    /** Bigger issue: a rider call sent to the person on the order (it opens the delivery issue with its timer). */
    public function openIssue(Order $order, Request $request, RiderCallService $calls): RedirectResponse
    {
        $data = $request->validate([
            'issue_type' => ['required', Rule::in(DeliveryIssueService::TYPES)],
            'rider_phone' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'verdict' => ['required', Rule::in(array_keys(RiderCallService::VERDICTS))],
        ], ['verdict.required' => __('Say what the customer said.')]);
        $claim = self::CLAIM_OF_ISSUE[$data['issue_type']] ?? 'other';
        $calls->record($request->user(), ['order_id' => $order->id, 'rider_phone' => $data['rider_phone'] ?? null, 'claim' => $claim, 'verdict' => $data['verdict'], 'action' => 'moderator',
            'note' => $data['note'] ?? null, 'issue_type' => $data['issue_type']]);

        return back()->with('success', __('Sent to :n with a timer.', ['n' => $order->moderator?->name ?? __('the managers')]));
    }

    /** What the rider said, for each delivery issue picked on Parcel status (the Riders report groups by it). */
    private const CLAIM_OF_ISSUE = ['no_answer' => 'no_answer', 'address' => 'address', 'cancel' => 'refused', 'hold' => 'later'];

    public function issues(Request $request): View
    {
        $user = $request->user();
        // Everyone working orders sees every open issue (anyone may solve one); Mine narrows to their own.
        $all = true;

        $list = \App\Support\Lists\ListState::from($request, ['sla_due_at'], ['show' => ['all', 'overdue', 'mine'], 'person' => 'int'], 'asc');
        $q = trim($list->search);
        $digits = \App\Support\Phone::searchPrefix($q);
        $issues = DB::table('delivery_issues as i')->join('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')
            ->whereNull('i.resolved_at')
            ->leftJoin('users as om', 'om.id', '=', 'o.moderator_id')
            ->when($list->filter('show') === 'mine', fn ($w) => $w->where('i.assigned_to', $user->id))
            ->when($list->filter('show') === 'overdue', fn ($w) => $w->where('i.sla_due_at', '<', now()))
            ->when($all && $list->filter('person'), fn ($w, $p) => $w->where('i.assigned_to', $p))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => \App\Support\Phone::orWhereOrder($s->where('o.order_no', strtoupper($q)), $digits)
                // A CN or tracking code (any parcel of the order, also an older one).
                ->when(strlen((string) $digits) >= 5 || preg_match('/^[A-Z]{2,}\w{6,}$/i', $q), fn ($d) => $d->orWhereIn('i.order_id', DB::table('shipments')->select('order_id')->where('consignment_id', $digits ?: $q)->orWhere('tracking_code', strtoupper($q))))))
            ->orderBy('i.sla_due_at')
            ->select(['i.*', 'o.order_no', 'o.ship_name', 'o.cod_amount', 'u.name as moderator', 'u.photo_path as moderator_photo', 'o.moderator_id as order_moderator_id', 'om.name as order_moderator', 'om.photo_path as order_moderator_photo'])
            ->paginate($list->perPage)->withQueryString();
        $people = $all ? DB::table('delivery_issues as i')->join('users as u', 'u.id', '=', 'i.assigned_to')->whereNull('i.resolved_at')
            ->distinct()->orderBy('u.name')->pluck('u.name', 'u.id')->all() : [];

        return view('hotline.issues', ['issues' => $issues, 'all' => $all, 'list' => $list, 'people' => $people]);
    }

    public function resolve(int $issue, Request $request, DeliveryIssueService $issues): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', Rule::in(DeliveryIssueService::RESOLUTIONS)], 'note' => ['nullable', 'string', 'max:500']]);
        $issues->resolve($issue, $data['resolution'], $data['note'] ?? null, $request->user());

        return back()->with('success', __('Issue closed.'));
    }
}
