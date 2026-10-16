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

    public function show(Request $request, int $list): View|RedirectResponse
    {
        if ($back = $this->remembered($request, 'calls.list.'.$list, 'calls.lists.show', ['list' => $list])) {
            return $back;
        }
        $row = $this->listRow($list);
        $f = $this->numberFilters($request);

        return view('calls.show', [
            'list' => $row,
            'progress' => $this->calls->progress($row),
            'callers' => $this->callers(),
            'assigned' => DB::table('call_list_user')->where('call_list_id', $list)->pluck('user_id')->all(),
            'numbers' => $this->numbersQuery($list, $f)->paginate($f['per'])->withQueryString()->fragment('numbers'),
            'filters' => $f,
            'overview' => $this->overview($list, $f),
            // Everyone who has called on this list, for the Caller filter.
            'calledBy' => DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')->join('users as u', 'u.id', '=', 'cc.user_id')
                ->where('i.call_list_id', $list)->distinct()->orderBy('u.name')->pluck('u.name', 'u.id')->all(),
            'seePhone' => $request->user()->canSeeField('customer_contact'),
        ]);
    }

    /** The numbers as filtered on the page, as an Excel file. */
    public function exportExcel(Request $request, int $list): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $row = $this->listRow($list);
        $f = $this->numberFilters($request);
        $see = $request->user()->canSeeField('customer_contact');
        $rows = $this->exportRows($list, $f);
        $file = \App\Support\Xlsx::write(__('Numbers'), [
            __('Customer'), __('Phone'), __('State'), __('Result'), __('Satisfied'), __('Good about'), __('Bad about'), __('Note'), __('Tries'), __('Last caller'), __('Last call'), __('Order'), __('Order total'),
        ], $rows->map(fn ($n) => [
            $n->name, $see ? $n->phone : \App\Support\Permissions\Mask::value($n->phone, 'customer_contact'), $this->stateLabel($n), $n->outcome ? __(CallListService::OUTCOMES[$n->outcome] ?? $n->outcome) : '',
            $n->satisfaction === null ? '' : $n->satisfaction.'%', $this->topicText($n->topics, 'good'), $this->topicText($n->topics, 'bad'), (string) $n->note, (int) $n->attempts,
            (string) $n->by, $n->attempts ? \Illuminate\Support\Carbon::parse($n->updated_at)->format('Y-m-d H:i') : '', (string) $n->order_no, $n->order_no ? (float) $n->grand_total : '',
        ]), [22, 14, 16, 14, 9, 22, 22, 40, 6, 14, 16, 12, 11]);
        app(\App\Services\ActivityLogger::class)->log('call_list.exported', ['call_list', $list], null, ['as' => 'excel', 'rows' => $rows->count()] + $f);

        return response()->download($file, $this->exportName($row, $f).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** The numbers as filtered on the page, as a page made for paper (Print, or Save as PDF). */
    public function exportPrint(Request $request, int $list): View
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        $row = $this->listRow($list);
        $f = $this->numberFilters($request);
        $rows = $this->exportRows($list, $f);
        app(\App\Services\ActivityLogger::class)->log('call_list.exported', ['call_list', $list], null, ['as' => 'print', 'rows' => $rows->count()] + $f);

        return view('calls.export-print', [
            'list' => $row, 'rows' => $rows, 'filters' => $f, 'overview' => $this->overview($list, $f), 'title' => $this->exportName($row, $f),
            'describe' => $this->describeFilters($f), 'seePhone' => $request->user()->canSeeField('customer_contact'),
            'stateLabel' => fn ($n) => $this->stateLabel($n), 'topicText' => fn ($t, $k) => $this->topicText($t, $k), 'max' => self::EXPORT_MAX,
        ]);
    }

    /** At most this many numbers in one download. */
    public const EXPORT_MAX = 20000;

    private function exportRows(int $list, array $f): \Illuminate\Support\Collection
    {
        return $this->numbersQuery($list, $f)->addSelect('i.topics')->limit(self::EXPORT_MAX)->get();
    }

    private function listRow(int $list): object
    {
        return DB::table('call_lists as l')->leftJoin('customer_imports as i', 'i.id', '=', 'l.customer_import_id')->where('l.id', $list)->first(['l.*', 'i.file_name']) ?? abort(404);
    }

    /** The filters of the Numbers tab (and the date range all tabs share); anything unknown is dropped. */
    private function numberFilters(Request $request): array
    {
        $date = fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d = (string) $request->query($k, '')) && strtotime($d) ? $d : '';

        return [
            'from' => $date('from'),
            'to' => $date('to'),
            'q' => trim((string) $request->query('q', '')),
            'state' => array_key_exists($s = (string) $request->query('state', ''), self::STATES) ? $s : '',
            'outcome' => array_key_exists($o = (string) $request->query('outcome', ''), CallListService::OUTCOMES) || $o === 'not_reached' ? $o : '',
            'caller' => ctype_digit($c = (string) $request->query('caller', '')) ? $c : '',
            'mood' => in_array($m = (string) $request->query('mood', ''), ['unhappy', 'okay', 'happy'], true) ? $m : '',
            'flag' => in_array($g = (string) $request->query('flag', ''), ['ordered', 'earlier'], true) ? $g : '',
            'sort' => array_key_exists($t = (string) $request->query('sort', 'recent'), self::SORTS) ? $t : 'recent',
            'per' => self::perPage($request),
        ];
    }

    /** How many rows a page shows: picked at the top of each list. */
    public const PER_PAGE = [10, 25, 50, 100];

    private static function perPage(Request $request): int
    {
        return in_array($p = (int) $request->query('per', 25), self::PER_PAGE, true) ? $p : 25;
    }

    /**
     * The filters a person last used on a list are kept (in their session): a reload, or coming back
     * from another page, opens the list as they left it. A form or a "Clear" sends clear=1, which sets
     * exactly what it sends (also nothing), so an emptied filter stays empty.
     */
    private function remembered(Request $request, string $key, string $route, array $params): ?RedirectResponse
    {
        $query = $request->query();
        if (array_key_exists('clear', $query)) {
            unset($query['clear']);
            $query = array_filter($query, fn ($v) => $v !== '' && $v !== null);
            $request->session()->put('filters.'.$key, \Illuminate\Support\Arr::except($query, ['page']));

            return redirect()->route($route, $params + $query);
        }
        if (! $query && ($saved = $request->session()->get('filters.'.$key))) {
            return redirect()->route($route, $params + $saved);
        }
        if ($query) {
            $request->session()->put('filters.'.$key, \Illuminate\Support\Arr::except($query, ['page']));
        }

        return null;
    }

    /** Calls of this list inside the date range (all of them without one). */
    private function callsInRange(int $list, array $f): \Illuminate\Database\Query\Builder
    {
        return DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')->where('i.call_list_id', $list)
            ->when($f['from'] !== '', fn ($q) => $q->where('cc.started_at', '>=', $f['from'].' 00:00:00'))
            ->when($f['to'] !== '', fn ($q) => $q->where('cc.started_at', '<=', $f['to'].' 23:59:59'));
    }

    private function numbersQuery(int $list, array $f): \Illuminate\Database\Query\Builder
    {
        $ranged = $f['from'] !== '' || $f['to'] !== '';

        return DB::table('call_list_items as i')->where('i.call_list_id', $list)
            ->leftJoin('users as u', 'u.id', '=', 'i.user_id')->leftJoin('orders as o', 'o.id', '=', 'i.order_id')
            // A date range keeps the numbers called in it.
            ->when($ranged, fn ($q) => $q->whereIn('i.id', $this->callsInRange($list, $f)->select('cc.call_list_item_id')))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $phone = \App\Support\Phone::normalize($f['q']);
                $q->where(fn ($w) => $phone ? $w->where('i.phone', $phone) : $w->where('i.name', 'like', '%'.$f['q'].'%')->orWhere('i.phone', 'like', '%'.preg_replace('/\D/', '', $f['q']).'%'));
            })
            ->when($f['state'] === 'not_called', fn ($q) => $q->where('i.attempts', 0)->where('i.state', '!=', 'in_hand'))
            ->when(in_array($f['state'], ['in_hand', 'later', 'done'], true), fn ($q) => $q->where('i.state', $f['state']))
            ->when($f['outcome'] === 'not_reached', fn ($q) => $q->whereIn('i.outcome', ['no_answer', 'busy']))
            ->when($f['outcome'] !== '' && $f['outcome'] !== 'not_reached', fn ($q) => $q->where('i.outcome', $f['outcome']))
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
            ->select(['i.id', 'i.name', 'i.phone', 'i.customer_id', 'i.state', 'i.attempts', 'i.outcome', 'i.satisfaction', 'i.note', 'i.next_at', 'i.updated_at', 'i.order_id', 'u.name as by', 'o.order_no', 'o.grand_total']);
    }

    /**
     * The Overview tab, for the date range: calls and how they went, how satisfied the customers were,
     * orders, what they complained about, and each caller.
     */
    private function overview(int $list, array $f): array
    {
        $calls = $this->callsInRange($list, $f);
        $byOutcome = (clone $calls)->groupBy('cc.outcome')->selectRaw('cc.outcome, COUNT(*) as n')->pluck('n', 'cc.outcome');
        // Each customer counted once, by how they felt on their latest call in the range.
        $moods = (clone $calls)->whereNotNull('cc.satisfaction')
            ->whereRaw('cc.id = (SELECT MAX(c2.id) FROM customer_calls c2 WHERE c2.call_list_item_id = cc.call_list_item_id AND c2.satisfaction IS NOT NULL'
                .($f['from'] !== '' ? " AND c2.started_at >= '".$f['from']." 00:00:00'" : '').($f['to'] !== '' ? " AND c2.started_at <= '".$f['to']." 23:59:59'" : '').')')
            ->pluck('cc.satisfaction');
        $items = (clone $calls)->distinct()->pluck('cc.call_list_item_id');
        $orders = DB::table('call_list_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->whereIn('i.id', $items);
        $topics = [];
        foreach (DB::table('call_list_items')->whereIn('id', $items)->whereNotNull('topics')->pluck('topics') as $t) {
            foreach (json_decode((string) $t, true) ?: [] as $k => $v) {
                $topics[$k][$v] = ($topics[$k][$v] ?? 0) + 1;
            }
        }
        $people = (clone $calls)->join('users as u', 'u.id', '=', 'cc.user_id')->groupBy('cc.user_id', 'u.name')
            ->selectRaw("cc.user_id, u.name, COUNT(*) as calls, SUM(CASE WHEN cc.outcome = 'answered' THEN 1 ELSE 0 END) as talked, AVG(cc.satisfaction) as sat, COUNT(DISTINCT i.order_id) as orders")
            ->orderByDesc('calls')->get();

        return [
            'calls' => (int) $byOutcome->sum(),
            'customers' => $items->count(),
            'talked' => (int) ($byOutcome['answered'] ?? 0),
            'not_reached' => (int) ($byOutcome['no_answer'] ?? 0) + (int) ($byOutcome['busy'] ?? 0),
            'wrong' => (int) ($byOutcome['wrong'] ?? 0),
            'happy' => $moods->filter(fn ($s) => $s > 50)->count(),
            'okay' => $moods->filter(fn ($s) => (int) $s === 50)->count(),
            'unhappy' => $moods->filter(fn ($s) => $s < 50)->count(),
            'satisfaction' => $moods->isEmpty() ? null : (int) round($moods->avg()),
            'orders' => (clone $orders)->count(),
            'sold' => (float) (clone $orders)->sum('o.grand_total'),
            'topics' => collect(CallListService::TOPICS)->map(fn ($label, $k) => ['label' => __($label), 'bad' => $topics[$k]['bad'] ?? 0, 'good' => $topics[$k]['good'] ?? 0])
                ->filter(fn ($t) => $t['bad'] || $t['good'])->sortByDesc('bad')->all(),
            'people' => $people,
        ];
    }

    private function stateLabel(object $n): string
    {
        return match (true) {
            $n->state === 'in_hand' => __('With :n now', ['n' => $n->by ?? '-']),
            $n->state === 'later' => __('Again :d', ['d' => $n->next_at ? \Illuminate\Support\Carbon::parse($n->next_at)->format('d M, g:i A') : '-']),
            $n->state === 'done' => __('Done'),
            default => __('Not called yet'),
        };
    }

    private function topicText(?string $topics, string $kind): string
    {
        return collect(json_decode((string) $topics, true) ?: [])->filter(fn ($v) => $v === $kind)->keys()
            ->map(fn ($k) => __(CallListService::TOPICS[$k] ?? $k))->join(', ');
    }

    /** "Unhappy, Done, 01 Oct 2026 to 15 Oct 2026" for the top of a download. */
    private function describeFilters(array $f): string
    {
        $bits = array_filter([
            $f['from'] !== '' || $f['to'] !== '' ? ($f['from'] !== '' ? \Illuminate\Support\Carbon::parse($f['from'])->format('d M Y') : __('Start')).' '.__('to').' '.($f['to'] !== '' ? \Illuminate\Support\Carbon::parse($f['to'])->format('d M Y') : __('today')) : __('All days'),
            $f['state'] !== '' ? __(self::STATES[$f['state']]) : null,
            $f['outcome'] !== '' ? __(CallListService::OUTCOMES[$f['outcome']] ?? 'No answer or off') : null,
            $f['caller'] !== '' ? DB::table('users')->where('id', (int) $f['caller'])->value('name') : null,
            ['unhappy' => __('Unhappy'), 'okay' => __('Okay'), 'happy' => __('Happy'), '' => null][$f['mood']],
            ['ordered' => __('Ordered on the call'), 'earlier' => __('Earlier file used'), '' => null][$f['flag']],
            $f['q'] !== '' ? '"'.$f['q'].'"' : null,
        ]);

        return implode(' · ', $bits);
    }

    private function exportName(object $list, array $f): string
    {
        $mood = ['unhappy' => ' unhappy', 'okay' => ' okay', 'happy' => ' happy', '' => ''][$f['mood']];

        return trim(preg_replace('/[^\w\- ]+/u', '', $list->name).$mood.' '.($f['from'] ?: 'start').' to '.($f['to'] ?: now()->toDateString()));
    }

    /** One number of a list, for the details popup: where it stands, every call on it with its file, and the order taken. */
    public function item(Request $request, int $list, int $item): \Illuminate\Http\JsonResponse
    {
        return $this->itemJson($this->itemRow($item, $list), $request->user(), null);
    }

    /** A number the caller has called, for their own history: only their own calls on it. */
    public function myItem(Request $request, int $item): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless(DB::table('customer_calls')->where('call_list_item_id', $item)->where('user_id', $user->id)->exists(), 404);

        return $this->itemJson($this->itemRow($item), $user, $user->id);
    }

    private function itemRow(int $item, ?int $list = null): object
    {
        return DB::table('call_list_items as i')->leftJoin('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->when($list, fn ($q) => $q->where('i.call_list_id', $list))->where('i.id', $item)
            ->first(['i.*', 'o.order_no', 'o.grand_total', 'u.name as holder']) ?? abort(404);
    }

    private function itemJson(object $it, User $user, ?int $onlyBy): \Illuminate\Http\JsonResponse
    {
        $see = $user->canSeeField('customer_contact');
        $when = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('d M Y, g:i A') : null;
        $calls = DB::table('customer_calls as cc')->leftJoin('users as u', 'u.id', '=', 'cc.user_id')->where('cc.call_list_item_id', $it->id)
            ->when($onlyBy, fn ($q) => $q->where('cc.user_id', $onlyBy))->orderByDesc('cc.id')
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
            'journey' => $it->customer_id && $user->can('customers.view') ? route('customers.show', $it->customer_id) : null,
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

        return redirect()->to(route('calls.lists.show', $list).'#settings')->with('success', __('Saved.'));
    }

    // ---- Callers -------------------------------------------------------------------------------

    /** Days the caller can look back over in their own history. */
    public const DAYS = ['all' => 'All days', 'today' => 'Today', 'yesterday' => 'Yesterday', '7' => 'Last 7 days', '30' => 'Last 30 days'];

    /** How a call went, as kept on each call. */
    public const CALL_RESULTS = ['answered' => 'Talked', 'no_answer' => 'No answer', 'busy' => 'Busy or off', 'wrong' => 'Wrong number'];

    public function mine(Request $request): View|RedirectResponse
    {
        if ($back = $this->remembered($request, 'calls.mine', 'calls.mine', [])) {
            return $back;
        }
        $user = $request->user();
        $f = [
            'per' => self::perPage($request),
            'q' => trim((string) $request->query('q', '')),
            'day' => array_key_exists($d = (string) $request->query('day', 'all'), self::DAYS) ? $d : 'all',
            'list' => ctype_digit($l = (string) $request->query('list', '')) ? $l : '',
            'result' => array_key_exists($r = (string) $request->query('result', ''), self::CALL_RESULTS) ? $r : '',
        ];
        // Only this person's own calls from the lists.
        $base = fn () => DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')->where('cc.user_id', $user->id);
        $calls = $base()->join('call_lists as l', 'l.id', '=', 'i.call_list_id')->leftJoin('orders as o', 'o.id', '=', 'i.order_id')
            ->when($f['q'] !== '', function ($q) use ($f) {
                $phone = \App\Support\Phone::normalize($f['q']);
                $q->where(fn ($w) => $phone ? $w->where('i.phone', $phone) : $w->where('i.name', 'like', '%'.$f['q'].'%')->orWhere('i.phone', 'like', '%'.preg_replace('/\D/', '', $f['q']).'%'));
            })
            ->when($f['day'] === 'today', fn ($q) => $q->where('cc.started_at', '>=', today()))
            ->when($f['day'] === 'yesterday', fn ($q) => $q->whereBetween('cc.started_at', [today()->subDay(), today()]))
            ->when(in_array($f['day'], ['7', '30'], true), fn ($q) => $q->where('cc.started_at', '>=', today()->subDays((int) $f['day'] - 1)))
            ->when($f['list'] !== '', fn ($q) => $q->where('i.call_list_id', (int) $f['list']))
            ->when($f['result'] !== '', fn ($q) => $q->where('cc.outcome', $f['result']))
            ->orderByDesc('cc.id')
            ->select(['cc.id', 'cc.started_at', 'cc.duration_seconds', 'cc.note', 'cc.satisfaction', 'cc.outcome', 'cc.proof_path', 'cc.proof_name', 'cc.proof_old',
                'i.id as item_id', 'i.name', 'i.phone', 'l.name as list', 'o.order_no'])
            ->paginate($f['per'])->withQueryString()->fragment('history');
        $today = $base()->where('cc.started_at', '>=', today());

        return view('calls.mine', [
            'lists' => $this->calls->mine($user),
            'calls' => $calls,
            'filters' => $f,
            'today' => [
                'calls' => (clone $today)->count(),
                'talked' => (clone $today)->where('cc.outcome', 'answered')->count(),
                'orders' => (clone $today)->whereNotNull('i.order_id')->distinct()->count('i.order_id'),
                'satisfaction' => ($s = (clone $today)->whereNotNull('cc.satisfaction')->avg('cc.satisfaction')) === null ? null : (int) round($s),
            ],
            // Numbers this person put off for later: when each comes back.
            'callBacks' => DB::table('call_list_items as i')->join('call_lists as l', 'l.id', '=', 'i.call_list_id')->where('i.state', 'later')
                ->whereExists(fn ($e) => $e->selectRaw('1')->from('customer_calls as cc')->whereColumn('cc.call_list_item_id', 'i.id')->where('cc.user_id', $user->id))
                ->orderBy('i.next_at')->limit(10)->get(['i.id', 'i.name', 'i.phone', 'i.next_at', 'l.name as list']),
            'myLists' => $base()->join('call_lists as l', 'l.id', '=', 'i.call_list_id')->distinct()->orderBy('l.name')->pluck('l.name', 'l.id')->all(),
            'seePhone' => $user->canSeeField('customer_contact'),
        ]);
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
