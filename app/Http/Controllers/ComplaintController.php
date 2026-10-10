<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StatusReason;
use App\Models\User;
use App\Services\Complaints\ComplaintService;
use App\Services\Complaints\RefundService;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplaintController extends Controller
{
    private const PHOTO_RULES = ['photos' => ['array', 'max:6'], 'photos.*' => ['image', 'max:4096']];

    public function __construct(private ComplaintService $complaints) {}

    /** One list query (simple pagination) plus two small counts. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $list = ListState::from($request, ['id', 'sla_due_at'], [
            'tab' => ['open', 'mine', 'all'],
            'status' => ['open', 'resolved'],
            'category' => 'int',
            'stage' => Complaint::STAGES,
            'assigned' => 'int',
            'from' => 'date',
            'to' => 'date',
        ], 'desc');
        $tab = $list->filter('tab') ?? 'open';
        $q = trim($list->search);
        $digits = preg_replace('/\D/', '', $q);

        $complaints = Complaint::query()->visibleTo($user, 'complaints.view')
            ->leftJoin('orders as o', 'o.id', '=', 'complaints.order_id')
            ->join('status_reasons as r', 'r.id', '=', 'complaints.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'complaints.assigned_to')
            ->select(['complaints.id', 'complaints.order_id', 'complaints.customer_name', 'complaints.customer_phone', 'complaints.source', 'complaints.status',
                'complaints.resolution', 'complaints.blame_stage', 'complaints.sla_due_at', 'complaints.escalated_at', 'complaints.created_at',
                'o.order_no', 'r.label_en as category', 'u.name as assignee'])
            ->when($tab === 'open', fn ($w) => $w->where('complaints.status', 'open'))
            ->when($tab === 'mine', fn ($w) => $w->where('complaints.assigned_to', $user->id)->where('complaints.status', 'open'))
            ->when($tab === 'all' && $list->filter('status'), fn ($w, $s) => $w->where('complaints.status', $s))
            ->when($list->filter('category'), fn ($w, $id) => $w->where('complaints.category_id', $id))
            ->when($list->filter('stage'), fn ($w, $s) => $w->where('complaints.blame_stage', $s))
            ->when($list->filter('assigned'), fn ($w, $id) => $w->where('complaints.assigned_to', $id))
            ->when($list->filter('from'), fn ($w, $d) => $w->where('complaints.created_at', '>=', $d.' 00:00:00'))
            ->when($list->filter('to'), fn ($w, $d) => $w->where('complaints.created_at', '<=', $d.' 23:59:59'))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('o.order_no', strtoupper($q))
                ->when(ctype_digit($q), fn ($s) => $s->orWhere('complaints.id', (int) $q))
                ->when(strlen($digits) >= 4, fn ($s) => $s->orWhere('complaints.customer_phone', 'like', (str_starts_with($digits, '0') ? $digits : '0'.$digits).'%'))
                ->orWhere('complaints.customer_name', 'like', $q.'%')))
            ->orderBy('complaints.'.$list->sort, $list->dir)->orderBy('complaints.id', $list->dir)
            ->simplePaginate($list->perPage)
            ->withQueryString();

        $counts = [
            'open' => Complaint::visibleTo($user, 'complaints.view')->where('status', 'open')->count(),
            'mine' => Complaint::where('assigned_to', $user->id)->where('status', 'open')->count(),
        ];

        return view('complaints.index', [
            'complaints' => $complaints, 'list' => $list, 'tab' => $tab, 'counts' => $counts,
            'categories' => StatusReason::options('complaint'),
            'staff' => $user->permissionScope('complaints.view') === 'all' ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function create(Request $request): View
    {
        $order = $request->query('order') ? Order::visibleTo($request->user())->select(['id', 'order_no', 'ship_name', 'ship_phone', 'moderator_id'])->find((int) $request->query('order')) : null;

        return view('complaints.create', [
            'order' => $order,
            'categories' => StatusReason::options('complaint'),
            'staff' => $request->user()->can('complaints.edit') ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:30'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'category_id' => ['required', 'integer'],
            'source' => ['required', Rule::in(Complaint::SOURCES)],
            'description' => ['required', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ] + self::PHOTO_RULES);

        if (! empty($data['order_no'])) {
            $order = Order::visibleTo($request->user())->where('order_no', strtoupper(trim($data['order_no'])))->first(['id']);
            if (! $order) {
                return back()->withInput()->withErrors(['order_no' => __('No order with that number.')]);
            }
            $data['order_id'] = $order->id;
        }
        if (! $request->user()->can('complaints.edit')) {
            unset($data['assigned_to']);
        }
        $complaint = $this->complaints->open($data, $request->file('photos', []), $request->user());

        return redirect()->route('complaints.show', $complaint)->with('success', __('Complaint #:id opened.', ['id' => $complaint->id]));
    }

    /** Five small queries: the complaint, its photos, timeline, refunds and the order summary. */
    public function show(Complaint $complaint, Request $request, RefundService $refunds): View
    {
        $user = $request->user();
        $this->guardVisible($complaint, $user);
        $complaint->load(['category:id,label_en,blame_stage', 'assignee:id,name']);

        $events = DB::table('complaint_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.complaint_id', $complaint->id)->orderByDesc('e.id')->limit(200)
            ->get(['e.action as note_type', 'e.body', 'e.created_at', 'u.name as user']);
        $order = $complaint->order_id ? Order::query()->select(['id', 'order_no', 'ship_name', 'ship_phone', 'status_id', 'moderator_id', 'packer_id', 'grand_total', 'advance_verified', 'cod_amount', 'payment_status', 'active_shipment_id', 'refund_due'])->find($complaint->order_id) : null;
        $refundRows = DB::table('refunds as f')->join('payment_methods as m', 'm.id', '=', 'f.method_id')
            ->where('f.complaint_id', $complaint->id)->orderBy('f.id')->get(['f.id', 'f.amount', 'f.status', 'f.transaction_id', 'f.created_at', 'm.name as method']);

        return view('complaints.show', [
            'complaint' => $complaint,
            'order' => $order,
            'photos' => DB::table('complaint_photos')->where('complaint_id', $complaint->id)->orderBy('id')->get(['id', 'original_name', 'size_bytes']),
            'events' => $events,
            'refunds' => $refundRows,
            'canEdit' => $user->can('complaints.edit') && ($complaint->assigned_to === $user->id || $user->permissionScope('complaints.view') === 'all'),
            'canRefund' => $order !== null && $user->can('refunds.create'),
            'maxRefund' => $order ? $refunds->maxRefundable($order) : 0,
            'methods' => DB::table('payment_methods')->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all(),
            'refundReasons' => StatusReason::options('refund'),
            'staff' => $user->can('complaints.edit') ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'statuses' => OrderStatus::map(),
            'blamedName' => $complaint->blamed_user_id ? DB::table('users')->where('id', $complaint->blamed_user_id)->value('name') : null,
        ]);
    }

    public function photo(Complaint $complaint, int $photo, Request $request): StreamedResponse
    {
        $this->guardVisible($complaint, $request->user());
        $row = DB::table('complaint_photos')->where('complaint_id', $complaint->id)->where('id', $photo)->first(['path', 'original_name']);
        abort_unless($row && Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, $row->original_name, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function note(Complaint $complaint, Request $request): RedirectResponse
    {
        $this->guardVisible($complaint, $request->user());
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $this->complaints->note($complaint, $data['body'], $request->user());

        return back()->with('success', __('Note added.'));
    }

    public function addPhotos(Complaint $complaint, Request $request): RedirectResponse
    {
        $this->guardVisible($complaint, $request->user());
        $request->validate(['photos' => ['required', 'array', 'max:6'], 'photos.*' => ['image', 'max:4096']]);
        $n = $this->complaints->addPhotos($complaint, $request->file('photos', []), $request->user());

        return back()->with('success', __(':n photo(s) added.', ['n' => $n]));
    }

    public function assign(Complaint $complaint, Request $request): RedirectResponse
    {
        $this->guardEditable($complaint, $request->user());
        $data = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);
        $this->complaints->assign($complaint, User::findOrFail($data['assigned_to']), $request->user());

        return back()->with('success', __('Complaint reassigned.'));
    }

    public function resolve(Complaint $complaint, Request $request): RedirectResponse
    {
        $this->guardEditable($complaint, $request->user());
        $data = $request->validate([
            'resolution' => ['required', Rule::in(Complaint::RESOLUTIONS)],
            'blame_stage' => ['required', Rule::in(Complaint::STAGES)],
            'blamed_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->complaints->resolve($complaint, $data['resolution'], $data['blame_stage'], $data['blamed_user_id'] ?? null, $data['note'] ?? null, $request->user());

        return back()->with('success', __('Complaint resolved.'));
    }

    public function reopen(Complaint $complaint, Request $request): RedirectResponse
    {
        $this->guardEditable($complaint, $request->user());
        $data = $request->validate(['why' => ['nullable', 'string', 'max:500']]);
        $this->complaints->reopen($complaint, $data['why'] ?? null, $request->user());

        return back()->with('success', __('Complaint reopened.'));
    }

    private function guardVisible(Complaint $complaint, User $user): void
    {
        abort_unless(Complaint::visibleTo($user, 'complaints.view')->whereKey($complaint->id)->exists(), 403);
    }

    private function guardEditable(Complaint $complaint, User $user): void
    {
        $this->guardVisible($complaint, $user);
        abort_unless($complaint->assigned_to === $user->id || $user->permissionScope('complaints.view') === 'all', 403);
    }
}
