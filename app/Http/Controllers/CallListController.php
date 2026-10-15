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

    public function show(int $list): View
    {
        $row = DB::table('call_lists as l')->leftJoin('customer_imports as i', 'i.id', '=', 'l.customer_import_id')->where('l.id', $list)->first(['l.*', 'i.file_name']) ?? abort(404);

        return view('calls.show', [
            'list' => $row,
            'progress' => $this->calls->progress($row),
            'callers' => $this->callers(),
            'assigned' => DB::table('call_list_user')->where('call_list_id', $list)->pluck('user_id')->all(),
            'recent' => DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')->leftJoin('users as u', 'u.id', '=', 'cc.user_id')
                ->where('i.call_list_id', $list)->orderByDesc('cc.id')->limit(30)
                ->get(['cc.id', 'cc.started_at', 'cc.note', 'cc.satisfaction', 'cc.proof_path', 'cc.duration_seconds', 'i.name', 'i.customer_id', 'u.name as by']),
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
        ]);
        $this->calls->record($it, $request->user(), $data, $request->file('proof'));

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
        $file = $this->calls->proofFile($call, $request->user()) ?? abort(404);
        // Its own type only, never sniffed, and nothing in it can run.
        $headers = [
            'Content-Type' => CallListService::proofMime($file->path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; sandbox",
        ];
        if (! $file->drive_id) {
            return Storage::disk('local')->response($file->path, null, $headers);
        }
        // In Google Drive: fetched through IQS, so the Drive file is never shared with anyone.
        try {
            $body = app(\App\Services\Drive\GoogleDriveService::class)->download(\App\Models\GoogleDrive::current(), $file->drive_id);
        } catch (\RuntimeException $e) {
            abort(503, __('The file is in Google Drive, but Drive did not give it back now. If Drive was disconnected, connect it again in Settings.'));
        }

        return response()->stream(function () use ($body) {
            echo $body;
        }, 200, $headers + ['Content-Length' => strlen($body), 'Cache-Control' => 'private, max-age=3600']);
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
