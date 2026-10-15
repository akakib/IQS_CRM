<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\Calls\CallListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Call lists. Managers (calls.manage) make a list from an imported file and
 * give it to callers with a daily target; callers (calls.work) see only the
 * lists given to them and work them one number at a time.
 */
class CallListController extends Controller
{
    public function __construct(private CallListService $calls) {}

    // ---- Managers ------------------------------------------------------------------------------

    public function index(): View
    {
        $lists = DB::table('call_lists as l')->leftJoin('customer_imports as i', 'i.id', '=', 'l.customer_import_id')
            ->orderByDesc('l.is_active')->orderByDesc('l.id')->get(['l.*', 'i.file_name']);

        return view('calls.lists', [
            'lists' => $lists->each(fn ($l) => $l->progress = $this->calls->progress($l)),
        ]);
    }

    public function create(): View
    {
        return view('calls.create', [
            'imports' => DB::table('customer_imports')->whereNotNull('done_at')->whereNull('undone_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'file_name', 'source', 'result', 'done_at'])
                ->mapWithKeys(fn ($i) => [$i->id => $i->file_name.' ('.number_format(json_decode((string) $i->result, true)['orders'] ?? 0).')'])->all(),
            'callers' => $this->callers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $id = DB::transaction(function () use ($data, $request) {
            $id = DB::table('call_lists')->insertGetId([
                'name' => $data['name'], 'purpose' => $data['purpose'], 'instructions' => $data['instructions'] ?? null,
                'daily_target' => $data['daily_target'], 'customer_import_id' => $data['import_id'],
                'filter' => json_encode(['status' => $data['status'] ?: null, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]),
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('call_list_user')->insert(array_map(fn ($u) => ['call_list_id' => $id, 'user_id' => $u], $data['callers']));
            $this->calls->fill($id, (int) $data['import_id'], ['status' => $data['status'] ?: null, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]);

            return $id;
        });

        return redirect()->route('calls.lists.show', $id)->with('success', __('Call list made.'));
    }

    /** Ways to sort the numbers of a list. */
    public const SORTS = ['recent' => 'Latest call first', 'name' => 'Name A to Z', 'unhappy' => 'Least satisfied first', 'tries' => 'Most tries first'];

    /** What a number's state means to a manager. */
    public const STATES = ['not_called' => 'Not called yet', 'in_hand' => 'With a caller now', 'later' => 'Call again later', 'done' => 'Done'];

    public function show(Request $request, int $list): View
    {
        $row = DB::table('call_lists as l')->leftJoin('customer_imports as i', 'i.id', '=', 'l.customer_import_id')->where('l.id', $list)->first(['l.*', 'i.file_name']) ?? abort(404);
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'state' => array_key_exists($s = (string) $request->query('state', ''), self::STATES) ? $s : '',
            'outcome' => array_key_exists($o = (string) $request->query('outcome', ''), CallListService::OUTCOMES) ? $o : '',
            'caller' => ctype_digit($c = (string) $request->query('caller', '')) ? $c : '',
            'mood' => in_array($m = (string) $request->query('mood', ''), ['unhappy', 'okay', 'happy'], true) ? $m : '',
            'flag' => in_array($g = (string) $request->query('flag', ''), ['ordered', 'earlier'], true) ? $g : '',
            'sort' => array_key_exists($t = (string) $request->query('sort', 'recent'), self::SORTS) ? $t : 'recent',
        ];

        $numbers = DB::table('call_list_items as i')->where('i.call_list_id', $list)
            ->leftJoin('users as u', 'u.id', '=', 'i.user_id')->leftJoin('orders as o', 'o.id', '=', 'i.order_id')
            ->when($f['q'] !== '', function ($q) use ($f) {
                $phone = \App\Support\Phone::normalize($f['q']);
                $q->where(fn ($w) => $phone ? $w->where('i.phone', $phone) : $w->where('i.name', 'like', '%'.$f['q'].'%')->orWhere('i.phone', 'like', '%'.preg_replace('/\D/', '', $f['q']).'%'));
            })
            ->when($f['state'] === 'not_called', fn ($q) => $q->where('i.attempts', 0)->where('i.state', '!=', 'in_hand'))
            ->when(in_array($f['state'], ['in_hand', 'later', 'done'], true), fn ($q) => $q->where('i.state', $f['state']))
            ->when($f['outcome'] !== '', fn ($q) => $q->where('i.outcome', $f['outcome']))
            ->when($f['caller'] !== '', fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('customer_calls as cc')->whereColumn('cc.call_list_item_id', 'i.id')->where('cc.user_id', (int) $f['caller'])))
            ->when($f['mood'] === 'unhappy', fn ($q) => $q->where('i.satisfaction', '<', 50))
            ->when($f['mood'] === 'okay', fn ($q) => $q->where('i.satisfaction', 50))
            ->when($f['mood'] === 'happy', fn ($q) => $q->where('i.satisfaction', '>', 50))
            ->when($f['flag'] === 'ordered', fn ($q) => $q->whereNotNull('i.order_id'))
            ->when($f['flag'] === 'earlier', fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('customer_calls as cc')->whereColumn('cc.call_list_item_id', 'i.id')->where('cc.proof_old', true)))
            ->when($f['sort'] === 'recent', fn ($q) => $q->orderByRaw('i.attempts = 0')->orderByDesc('i.updated_at'))
            ->when($f['sort'] === 'name', fn ($q) => $q->orderBy('i.name'))
            ->when($f['sort'] === 'unhappy', fn ($q) => $q->orderByRaw('i.satisfaction IS NULL')->orderBy('i.satisfaction'))
            ->when($f['sort'] === 'tries', fn ($q) => $q->orderByDesc('i.attempts'))
            ->orderBy('i.id')
            ->select(['i.id', 'i.name', 'i.phone', 'i.customer_id', 'i.state', 'i.attempts', 'i.outcome', 'i.satisfaction', 'i.note', 'i.next_at', 'i.updated_at', 'i.order_id', 'u.name as by', 'o.order_no', 'o.grand_total'])
            ->paginate(25)->withQueryString()->fragment('numbers');

        return view('calls.show', [
            'list' => $row,
            'progress' => $this->calls->progress($row),
            'callers' => $this->callers(),
            'assigned' => DB::table('call_list_user')->where('call_list_id', $list)->pluck('user_id')->all(),
            'numbers' => $numbers,
            'filters' => $f,
            // Everyone who has called on this list, for the Caller filter.
            'calledBy' => DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')->join('users as u', 'u.id', '=', 'cc.user_id')
                ->where('i.call_list_id', $list)->distinct()->orderBy('u.name')->pluck('u.name', 'u.id')->all(),
            'seePhone' => $request->user()->canSeeField('customer_contact'),
        ]);
    }

    /** One number of a list, for the details popup: where it stands, every call on it with its file, and the order taken. */
    public function item(Request $request, int $list, int $item): \Illuminate\Http\JsonResponse
    {
        $it = DB::table('call_list_items as i')->leftJoin('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->where('i.call_list_id', $list)->where('i.id', $item)->first(['i.*', 'o.order_no', 'o.grand_total', 'u.name as holder']) ?? abort(404);
        $user = $request->user();
        $see = $user->canSeeField('customer_contact');
        $when = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('d M Y, g:i A') : null;
        $calls = DB::table('customer_calls as cc')->leftJoin('users as u', 'u.id', '=', 'cc.user_id')->where('cc.call_list_item_id', $it->id)->orderByDesc('cc.id')
            ->get(['cc.id', 'cc.started_at', 'cc.ended_at', 'cc.duration_seconds', 'cc.note', 'cc.satisfaction', 'cc.proof_path', 'cc.proof_name', 'cc.proof_old', 'u.name as by']);
        $n = $calls->count();

        return response()->json([
            'name' => $it->name,
            'phone' => $see ? $it->phone : \App\Support\Permissions\Mask::value($it->phone, 'customer_contact'),
            'state' => __(match (true) { $it->state === 'in_hand' => self::STATES['in_hand'], $it->state === 'later' => self::STATES['later'], $it->state === 'done' => self::STATES['done'], default => self::STATES['not_called'] }),
            'holder' => $it->state === 'in_hand' ? $it->holder : null,
            'next_at' => $it->state === 'later' ? $when($it->next_at) : null,
            'attempts' => (int) $it->attempts,
            'outcome' => $it->outcome ? __(CallListService::OUTCOMES[$it->outcome] ?? $it->outcome) : null,
            'satisfaction' => $it->satisfaction === null ? null : ['value' => (int) $it->satisfaction, 'label' => __(CallListService::SATISFACTION[(int) $it->satisfaction] ?? '')],
            'topics' => collect(json_decode((string) $it->topics, true) ?: [])->map(fn ($v, $k) => ['label' => __(CallListService::TOPICS[$k] ?? $k), 'good' => $v === 'good'])->values(),
            'note' => $it->note,
            'order' => $it->order_id ? ['no' => $it->order_no, 'total' => number_format((float) $it->grand_total), 'url' => $user->can('orders.view') ? route('orders.show', $it->order_id) : null] : null,
            'journey' => $it->customer_id && $user->can('customers.view') ? route('journey.show', $it->customer_id) : null,
            'calls' => $calls->values()->map(fn ($c, $k) => [
                'try' => $n - $k,
                'when' => $when($c->started_at),
                'by' => $c->by,
                'duration' => $c->duration_seconds !== null ? intdiv($c->duration_seconds, 60).':'.str_pad((string) ($c->duration_seconds % 60), 2, '0', STR_PAD_LEFT) : null,
                'note' => $c->note,
                'satisfaction' => $c->satisfaction,
                'proof' => $c->proof_path ? [
                    'url' => route('calls.proof', $c->id),
                    'kind' => str_starts_with(CallListService::proofMime($c->proof_path), 'image/') ? 'image' : 'audio',
                    'title' => $it->name, 'sub' => trim(($c->by ?? '').' · '.$when($c->started_at)),
                    'picked' => $c->proof_name, 'old' => (bool) $c->proof_old,
                ] : null,
            ]),
        ]);
    }

    public function update(Request $request, int $list): RedirectResponse
    {
        DB::table('call_lists')->find($list) ?? abort(404);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($data, $list) {
            DB::table('call_lists')->where('id', $list)->update([
                'name' => $data['name'], 'purpose' => $data['purpose'], 'instructions' => $data['instructions'] ?? null,
                'daily_target' => $data['daily_target'], 'is_active' => (bool) ($data['is_active'] ?? false), 'updated_at' => now(),
            ]);
            DB::table('call_list_user')->where('call_list_id', $list)->delete();
            DB::table('call_list_user')->insert(array_map(fn ($u) => ['call_list_id' => $list, 'user_id' => $u], $data['callers']));
            // Someone taken off the list hands back the number they held (a call-back keeps its place).
            CallListService::giveBack(DB::table('call_list_items')->where('call_list_id', $list)->where('state', 'in_hand')->whereNotIn('user_id', $data['callers']));
        });

        return redirect()->route('calls.lists.show', $list)->with('success', __('Saved.'));
    }

    // ---- Callers -------------------------------------------------------------------------------

    public function mine(Request $request): View
    {
        return view('calls.mine', ['lists' => $this->calls->mine($request->user())]);
    }

    public function work(Request $request, int $list): View|RedirectResponse
    {
        $row = $this->myList($request->user(), $list);
        $item = $this->calls->next($row->id, $request->user());
        $mine = $this->calls->mine($request->user())->firstWhere('id', $row->id);

        return view('calls.work', [
            'list' => $row,
            'item' => $item,
            'today' => $mine->today ?? 0,
            'left' => $mine->left ?? 0,
            'history' => $item ? $this->history($item) : null,
            'canOrder' => $request->user()->can('orders.create'),
            'seePhone' => $request->user()->canSeeField('customer_contact'),
        ]);
    }

    public function record(Request $request, int $list, int $item): RedirectResponse
    {
        $row = $this->myList($request->user(), $list);
        $it = DB::table('call_list_items')->where('call_list_id', $row->id)->find($item) ?? abort(404);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(CallListService::OUTCOMES))],
            'satisfaction' => ['nullable', 'integer', Rule::in(array_keys(CallListService::SATISFACTION))],
            'topics' => ['nullable', 'array'], 'topics.*' => ['nullable', Rule::in(['good', 'bad'])],
            'note' => ['nullable', 'string', 'max:500'],
            'later_at' => ['nullable', 'date', 'after:now'],
            'seconds' => ['nullable', 'integer', 'min:0'],
            'proof' => ['nullable', 'file', 'max:25600'],
            'proof_modified' => ['nullable', 'string', 'max:20'], 'proof_old_ok' => ['nullable', 'boolean'],
        ]);
        $this->calls->record($it, $request->user(), $data, $request->file('proof'));
        // No cron on staging: the file goes to Drive right after the caller gets the next page (cron, where it runs, catches the rest).
        dispatch(fn () => \Illuminate\Support\Facades\Artisan::call('calls:proofs-to-drive', ['--limit' => 5]))->afterResponse();

        return redirect()->route('calls.work', $row->id)->with('success', __('Saved. Next number below.'))->with('saved_item', $it->id);
    }

    /** Call tapped: the caller is on this number, so the hold starts again. */
    public function touch(Request $request, int $list, int $item): \Illuminate\Http\JsonResponse
    {
        $this->myList($request->user(), $list);
        $this->calls->touch($item, $request->user());

        return response()->json(['ok' => true]);
    }

    public function skip(Request $request, int $list, int $item): RedirectResponse
    {
        $row = $this->myList($request->user(), $list);
        $it = DB::table('call_list_items')->where('call_list_id', $row->id)->find($item) ?? abort(404);
        $this->calls->release($it, $request->user());

        return redirect()->route('calls.mine')->with('success', __('The number went back to the list.'));
    }

    /** The recording or screenshot of one call: the caller and managers only. */
    public function proof(Request $request, int $call): \Symfony\Component\HttpFoundation\Response
    {
        return app(\App\Services\Calls\CallProof::class)->response('customer_calls', $call, $request->user());
    }

    // ---- Helpers -------------------------------------------------------------------------------

    /** A list given to this person (managers may open any). */
    private function myList(User $user, int $list): object
    {
        $row = DB::table('call_lists')->find($list) ?? abort(404);
        $given = DB::table('call_list_user')->where('call_list_id', $list)->where('user_id', $user->id)->exists();
        abort_unless($given, 403, __('This list is not given to you.'));
        abort_unless($row->is_active, 403, __('This list is paused.'));

        return $row;
    }

    /** People who may call: active staff with calls.work. */
    private function callers(): array
    {
        return User::where('is_active', true)->orderBy('name')->get(['id', 'name'])->filter(fn ($u) => $u->can('calls.work'))->pluck('name', 'id')->all();
    }

    private function validated(Request $request, bool $new): array
    {
        $callers = array_keys($this->callers());

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', Rule::in(array_keys(CallListService::PURPOSES))],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'daily_target' => ['required', 'integer', 'min:1', 'max:500'],
            'callers' => ['required', 'array', 'min:1'], 'callers.*' => ['integer', Rule::in($callers)],
            'is_active' => ['nullable', 'boolean'],
        ] + ($new ? [
            'import_id' => ['required', 'integer', Rule::exists('customer_imports', 'id')->whereNotNull('done_at')->whereNull('undone_at')],
            'status' => ['nullable', Rule::in(['', 'delivered', 'returned', 'cancelled', 'refunded'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ] : []), ['callers.required' => __('Pick at least one person to call.')]);
    }

    /** What the caller should know before dialing: this customer's orders and earlier calls. */
    private function history(object $item): array
    {
        $past = $item->customer_id ? DB::table('customer_past_orders')->where('customer_id', $item->customer_id)->orderByDesc('ordered_at')->limit(5)
            ->get(['ordered_at as at', 'amount', 'status', 'items', 'source']) : collect();
        $orders = $item->customer_id ? Order::where('customer_id', $item->customer_id)->with('items:id,order_id,name_snapshot,qty,unit')->latest('id')->limit(5)
            ->get(['id', 'order_no', 'status_id', 'grand_total', 'created_at']) : collect();
        $calls = DB::table('customer_calls as cc')->leftJoin('users as u', 'u.id', '=', 'cc.user_id')->where('cc.phone', $item->phone)
            ->whereNotNull('cc.outcome')->orderByDesc('cc.started_at')->limit(5)->get(['cc.started_at', 'cc.note', 'cc.satisfaction', 'u.name as by']);

        return ['past' => $past, 'orders' => $orders, 'calls' => $calls];
    }
}
