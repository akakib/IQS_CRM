<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Customers\HistoryImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Past orders from a CSV or Excel file: upload, map the columns, preview, import. */
class CustomerImportController extends Controller
{
    public function __construct(private HistoryImport $history) {}

    public function index(): View
    {
        return view('customers.history-import', [
            'imports' => DB::table('customer_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')
                ->orderByDesc('i.id')->limit(15)->get(['i.id', 'i.file_name', 'i.source', 'i.row_count', 'i.result', 'i.done_at', 'i.created_at', 'u.name as by']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        try {
            [$header, $rows] = $this->history->read($file->getRealPath(), $ext === 'xlsx' ? 'xlsx' : 'csv');
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        $path = 'imports/history-'.Str::uuid().'.json';
        Storage::disk('local')->put($path, json_encode([$header, $rows], JSON_UNESCAPED_UNICODE));
        $template = DB::table('import_templates')->where('signature', $this->history->signature($header))->first();

        $id = DB::table('customer_imports')->insertGetId([
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'path' => $path,
            'columns' => json_encode($this->history->columns($header, $rows), JSON_UNESCAPED_UNICODE), 'row_count' => count($rows),
            'source' => $template?->name, 'mapping' => $template?->mapping, 'values' => $template?->values,
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('customers.history.map', $id);
    }

    public function map(int $import): View|RedirectResponse
    {
        $row = DB::table('customer_imports')->find($import) ?? abort(404);
        if ($row->done_at) {
            return redirect()->route('customers.history.show', $import);
        }
        $columns = json_decode($row->columns, true);
        $header = array_column($columns, 'header');
        $values = json_decode((string) $row->values, true) ?: [];
        // Each word's meaning: the saved template's, else a guess.
        foreach ($columns as $c) {
            foreach ($c['values'] ?? [] as $w) {
                $values['status'][$w] ??= $this->history->guessValue('status', $w);
                $values['medium'][$w] ??= $this->history->guessValue('medium', $w);
            }
        }

        return view('customers.history-map', [
            'import' => $row,
            'columns' => $columns,
            'mapping' => array_merge($this->history->guess($header), array_intersect_key(json_decode((string) $row->mapping, true) ?: [], array_flip($header))),
            'values' => $values,
            'fromTemplate' => (bool) $row->source,
            'channels' => array_map(fn ($l) => __($l), Order::CHANNELS),
        ]);
    }

    public function preview(Request $request, int $import): JsonResponse
    {
        [$row, $plan, $data] = $this->plan($request, $import);

        return response()->json(['counts' => $plan['counts'], 'sample' => array_slice($plan['orders'], 0, 5)]);
    }

    public function run(Request $request, int $import): JsonResponse
    {
        [$row, $plan, $data] = $this->plan($request, $import);
        $source = $data['source'];
        $this->history->import($plan, $row->id, $source, $request->user()->id);
        $header = array_column(json_decode($row->columns, true), 'header');
        // Remember this file shape for next time.
        DB::table('import_templates')->updateOrInsert(['signature' => $this->history->signature($header)], [
            'name' => $source, 'mapping' => json_encode($data['mapping'], JSON_UNESCAPED_UNICODE), 'values' => json_encode($data['values'] ?? [], JSON_UNESCAPED_UNICODE),
            'default_medium' => $data['default_medium'] ?? null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        DB::table('customer_imports')->where('id', $row->id)->update([
            'source' => $source, 'mapping' => json_encode($data['mapping'], JSON_UNESCAPED_UNICODE), 'values' => json_encode($data['values'] ?? [], JSON_UNESCAPED_UNICODE),
            'result' => json_encode($plan['counts']), 'done_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('local')->delete($row->path);

        return response()->json(['url' => route('customers.history.show', $row->id)]);
    }

    public function show(int $import): View
    {
        $row = DB::table('customer_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')->where('i.id', $import)
            ->first(['i.*', 'u.name as by']) ?? abort(404);

        return view('customers.history-show', [
            'import' => $row,
            'result' => json_decode((string) $row->result, true) ?: [],
            'recent' => DB::table('customer_past_orders as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->where('p.customer_import_id', $import)
                ->orderByDesc('p.ordered_at')->limit(10)->get(['c.id', 'c.name', 'p.external_no', 'p.ordered_at', 'p.amount', 'p.status']),
        ]);
    }

    /** @return array{0: object, 1: array, 2: array} */
    private function plan(Request $request, int $import): array
    {
        $row = DB::table('customer_imports')->find($import) ?? abort(404);
        abort_if((bool) $row->done_at, 422, __('This file was imported already.'));
        $fields = array_keys(HistoryImport::FIELDS);
        $data = $request->validate([
            'mapping' => ['required', 'array'], 'mapping.*' => ['nullable', Rule::in(['', ...$fields])],
            'values' => ['nullable', 'array'], 'values.status' => ['nullable', 'array'], 'values.status.*' => [Rule::in(array_keys(HistoryImport::STATUSES))],
            'values.medium' => ['nullable', 'array'], 'values.medium.*' => [Rule::in(array_keys(Order::CHANNELS))],
            'default_medium' => ['nullable', Rule::in(array_keys(Order::CHANNELS))],
            'source' => ['required', 'string', 'max:80'],
        ]);
        $used = array_count_values(array_filter($data['mapping']));
        if (($used['phone'] ?? 0) !== 1) {
            abort(422, __('Pick exactly one Phone column.'));
        }
        if (! ($used['name'] ?? 0)) {
            abort(422, __('Pick the Name column.'));
        }
        foreach ($used as $field => $n) {
            if ($n > 1 && ! in_array($field, ['name', 'address'], true)) {
                abort(422, __(':f is picked for two columns.', ['f' => __(HistoryImport::FIELDS[$field])]));
            }
        }
        $data['source'] = trim($data['source']);
        [$header, $rows] = json_decode(Storage::disk('local')->get($row->path) ?? abort(410), true);

        return [$row, $this->history->plan($header, $rows, $data['mapping'], $data['values'] ?? [], $data['default_medium'] ?? null, $data['source']), $data];
    }
}
