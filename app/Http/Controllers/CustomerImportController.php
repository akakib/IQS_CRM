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

    public function sample(): \Symfony\Component\HttpFoundation\Response
    {
        return response(HistoryImport::sampleXlsx(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="iqs-orders-sample.xlsx"',
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
        $json = json_encode([$header, $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $columns = json_encode($this->history->columns($header, $rows), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || $columns === false) {
            return back()->withErrors(['file' => __('The file could not be read. Save it again as .xlsx and upload that.')]);
        }
        $path = 'imports/history-'.Str::uuid().'.json';
        Storage::disk('local')->put($path, $json);
        $saved = $this->saved($header);
        $name = mb_substr($file->getClientOriginalName(), 0, 200);
        // My own unfinished drafts of this file are replaced; anyone's draft untouched for a day goes too.
        $stale = DB::table('customer_imports')->whereNull('done_at')
            ->where(fn ($q) => $q->where(fn ($m) => $m->where('file_name', $name)->where('created_by', $request->user()->id))->orWhere('updated_at', '<', now()->subDay()))
            ->get(['id', 'path']);
        foreach ($stale as $old) {
            Storage::disk('local')->delete($old->path);
        }
        DB::table('customer_imports')->whereIn('id', $stale->pluck('id'))->delete();

        $id = DB::table('customer_imports')->insertGetId([
            'file_name' => $name, 'path' => $path,
            'columns' => $columns, 'row_count' => count($rows),
            'source' => $saved['source'], 'mapping' => $saved['mapping'] ? json_encode($saved['mapping'], JSON_UNESCAPED_UNICODE) : null,
            'values' => json_encode($saved['values'], JSON_UNESCAPED_UNICODE), 'default_medium' => $saved['default_medium'],
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

        // What was picked before for a column of the same name wins; a guess only fills the rest (and never a field already taken).
        $saved = array_intersect_key(json_decode((string) $row->mapping, true) ?: [], array_flip($header));
        $taken = array_filter($saved);
        $guess = array_map(fn ($f) => $f !== '' && in_array($f, $taken, true) && ! in_array($f, ['name', 'address'], true) ? '' : $f, $this->history->guess($header));

        return view('customers.history-map', [
            'import' => $row,
            'columns' => $columns,
            'mapping' => array_merge($guess, $saved),
            'savedKeys' => array_keys($saved),
            'values' => $values,
            'fromTemplate' => $saved !== [],
            // Our own sample file: every column is known, so the check runs by itself and the columns stay folded.
            'isSample' => array_diff(array_map('strtolower', $header), array_map('strtolower', HistoryImport::SAMPLE[0])) === []
                && count(array_intersect(array_map('strtolower', $header), ['name', 'phone'])) === 2,
            'channels' => array_map(fn ($l) => __($l), Order::CHANNELS),
            'defaultMedium' => $row->default_medium ?: 'other',
        ]);
    }

    public function preview(Request $request, int $import): JsonResponse
    {
        [$row, $plan, $data] = $this->plan($request, $import);
        // Import runs only with exactly what was checked here.
        DB::table('customer_imports')->where('id', $row->id)->update(['preview_hash' => $this->hash($data), 'updated_at' => now()]);

        return response()->json(['counts' => $plan['counts'], 'sample' => array_slice($plan['orders'], 0, 5)]);
    }

    public function run(Request $request, int $import): JsonResponse
    {
        set_time_limit(300); // a lifetime file updates thousands of rows
        [$row, $plan, $data] = $this->plan($request, $import);
        abort_unless($row->preview_hash && hash_equals($row->preview_hash, $this->hash($data)), 422, __('Press Check first; the columns changed since.'));
        // Claim the checked draft: a second tab or a retry while this runs finds nothing to run.
        $claimed = DB::table('customer_imports')->where('id', $row->id)->whereNull('done_at')->where('preview_hash', $row->preview_hash)->update(['preview_hash' => null]);
        abort_unless($claimed === 1, 422, __('This file is being imported already.'));
        $source = $data['source'];
        try {
            $written = $this->history->import($plan, $row->id, $source, $request->user()->id);
        } catch (\Throwable $e) {
            DB::table('customer_imports')->where('id', $row->id)->update(['preview_hash' => $row->preview_hash]);
            throw $e;
        }
        $plan['counts']['orders'] = $written['inserted'];
        $header = array_column(json_decode($row->columns, true), 'header');
        // Remember this file shape for next time.
        DB::table('import_templates')->updateOrInsert(['signature' => $this->history->signature($header)], [
            'name' => $source, 'mapping' => json_encode($data['mapping'], JSON_UNESCAPED_UNICODE), 'values' => json_encode($data['values'] ?? [], JSON_UNESCAPED_UNICODE),
            'default_medium' => $data['default_medium'] ?? null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        DB::table('customer_imports')->where('id', $row->id)->update([
            'source' => $source, 'mapping' => json_encode($data['mapping'], JSON_UNESCAPED_UNICODE), 'values' => json_encode($data['values'] ?? [], JSON_UNESCAPED_UNICODE),
            'default_medium' => $data['default_medium'] ?? null, 'result' => json_encode($plan['counts']),
            'changes' => $written['changes'] ? json_encode($written['changes'], JSON_UNESCAPED_UNICODE) : null, 'done_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('local')->delete($row->path);

        return response()->json(['url' => route('customers.history.show', $row->id)]);
    }

    /** Takes an import back: its orders, and the customers it created that have nothing else in IQS. */
    public function undo(int $import): RedirectResponse
    {
        $row = DB::table('customer_imports')->find($import) ?? abort(404);
        abort_unless($row->done_at && ! $row->undone_at, 422, __('Nothing to undo.'));
        DB::transaction(function () use ($row) {
            foreach (json_decode((string) $row->changes, true) ?: [] as $id => $old) {
                DB::table('customer_past_orders')->where('id', $id)->update($old + ['updated_at' => now()]);
            }
            DB::table('customer_past_orders')->where('customer_import_id', $row->id)->delete();
            DB::table('customers')->where('customer_import_id', $row->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')->whereColumn('orders.customer_id', 'customers.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_past_orders as p')->whereColumn('p.customer_id', 'customers.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customers as m')->whereColumn('m.merged_into_id', 'customers.id'))
                ->delete();
            DB::table('customer_imports')->where('id', $row->id)->update(['undone_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('customers.history.show', $row->id)->with('status', __('Import taken back.'));
    }

    public function show(int $import): View
    {
        $row = DB::table('customer_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')->where('i.id', $import)
            ->first(['i.*', 'u.name as by']) ?? abort(404);

        return view('customers.history-show', [
            'import' => $row,
            'result' => json_decode((string) $row->result, true) ?: [],
            'recent' => DB::table('customer_past_orders as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->where('p.customer_import_id', $import)
                ->orderByDesc('p.ordered_at')->limit(10)->get(['c.id', 'c.name', 'p.external_no', 'p.cn', 'p.ordered_at', 'p.amount', 'p.status']),
        ]);
    }

    /**
     * Mapping learned from earlier imports, by column name: the newest pick for a column of that name.
     *
     * @return array{mapping: array<string, string>, values: array, source: ?string, default_medium: ?string}
     */
    private function saved(array $header): array
    {
        $mapping = [];
        $values = ['status' => [], 'medium' => []];
        $source = null;
        $medium = null;
        foreach (DB::table('import_templates')->orderByDesc('updated_at')->orderByDesc('id')->get() as $t) {
            foreach (array_intersect_key(json_decode($t->mapping, true) ?: [], array_flip($header)) as $h => $f) {
                if (array_key_exists($h, $mapping)) {
                    continue;
                }
                // A field that takes one column (all but name and address) stays where the newer file put it.
                $mapping[$h] = $f && ! in_array($f, ['name', 'address'], true) && in_array($f, $mapping, true) ? '' : (string) $f;
            }
            foreach (['status', 'medium'] as $k) {
                $values[$k] += (json_decode((string) $t->values, true) ?: [])[$k] ?? [];
            }
            if ($t->signature === $this->history->signature($header)) {
                $source = $t->name;
                $medium = $t->default_medium;
            }
            $medium ??= $t->default_medium;
        }

        return ['mapping' => $mapping, 'values' => $values, 'source' => $source, 'default_medium' => $medium];
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode([$data['mapping'], $data['values'] ?? [], $data['default_medium'] ?? null, $data['source']]));
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
            'source' => ['nullable', 'string', 'max:80'],
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
        // No name given: the file's own name.
        $data['source'] = mb_substr(trim((string) ($data['source'] ?? '')) ?: pathinfo($row->file_name, PATHINFO_FILENAME), 0, 80);
        [$header, $rows] = json_decode(Storage::disk('local')->get($row->path) ?? abort(410, __('This upload is gone. Upload the file again.')), true) ?: abort(410, __('This upload is gone. Upload the file again.'));
        // Only the words of the columns used for status and medium are kept (not every name and phone of a small file).
        $words = fn (string $field) => array_flip(collect(json_decode($row->columns, true))->filter(fn ($c) => ($data['mapping'][$c['header']] ?? '') === $field)->flatMap(fn ($c) => $c['values'] ?? [])->map(fn ($w) => (string) $w)->all());
        foreach (['status', 'medium'] as $k) {
            $data['values'][$k] = array_intersect_key($data['values'][$k] ?? [], $words($k));
        }

        return [$row, $this->history->plan($header, $rows, $data['mapping'], $data['values'] ?? [], $data['default_medium'] ?? null, $data['source']), $data];
    }
}
