<?php

namespace App\Http\Controllers;

use App\Services\Customers\HistoryImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/** Past orders from a CSV or Excel file (the sample's columns, known by name): upload and it imports; Undo takes it back. */
class CustomerImportController extends Controller
{
    public function __construct(private HistoryImport $history) {}

    public function index(): View
    {
        return view('customers.history-import', [
            'imports' => DB::table('customer_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')->whereNotNull('i.done_at')
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
        // No column mapping: the columns are known by their names (the sample's own, or what was picked before
        // for that name, else a guess). Name and Phone must be found; then it imports straight away.
        $mapping = $this->autoMapping($header, $saved['mapping']);
        $used = array_count_values(array_filter($mapping));
        if (($used['phone'] ?? 0) !== 1 || ! ($used['name'] ?? 0)) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => __('Name and Phone columns were not found. Download the sample file, put the data in its columns and upload that.')]);
        }
        $values = ['status' => [], 'medium' => []];
        foreach ($this->history->columns($header, $rows) as $c) {
            foreach (['status', 'medium'] as $k) {
                if (($mapping[$c['header']] ?? '') === $k) {
                    foreach ($c['values'] ?? [] as $w) {
                        $values[$k][$w] = $saved['values'][$k][$w] ?? $this->history->guessValue($k, (string) $w);
                    }
                }
            }
        }
        $source = mb_substr($saved['source'] ?: pathinfo($name, PATHINFO_FILENAME), 0, 80);
        $medium = $saved['default_medium'] ?: 'other';

        set_time_limit(300); // a lifetime file writes thousands of rows
        $id = DB::table('customer_imports')->insertGetId([
            'file_name' => $name, 'path' => $path, 'columns' => $columns, 'row_count' => count($rows),
            'source' => $source, 'mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE), 'values' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'default_medium' => $medium, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $plan = $this->history->plan($header, $rows, $mapping, $values, $medium, $source);
        $written = $this->history->import($plan, $id, $source, $request->user()->id);
        $plan['counts']['orders'] = $written['inserted'];
        DB::table('customer_imports')->where('id', $id)->update([
            'result' => json_encode($plan['counts'] + ['problems' => $plan['problems']], JSON_UNESCAPED_UNICODE), 'changes' => $written['changes'] ? json_encode($written['changes'], JSON_UNESCAPED_UNICODE) : null,
            'done_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('local')->delete($path);

        return redirect()->route('customers.history.show', $id)->with('success', __('Imported. Wrong? Undo it below.'));
    }

    /** The field of each column, by its name: what was picked before for that name wins, a guess fills the rest; a one-column field once. */
    private function autoMapping(array $header, array $saved): array
    {
        $saved = array_intersect_key($saved, array_flip($header));
        $taken = array_filter($saved);
        $guess = array_map(fn ($f) => $f !== '' && in_array($f, $taken, true) && ! in_array($f, ['name', 'address'], true) ? '' : $f, $this->history->guess($header));

        return array_merge($guess, $saved);
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

    public const SORTS = ['new' => 'Newest order first', 'old' => 'Oldest order first', 'high' => 'Amount: high to low', 'low' => 'Amount: low to high', 'name' => 'Name A to Z'];

    /** One import: its numbers, then every order it brought in (search, status, sort, pages), then the rows that did not come in. */
    public function show(Request $request, int $import): View
    {
        $row = DB::table('customer_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')->where('i.id', $import)
            ->first(['i.*', 'u.name as by']) ?? abort(404);
        $q = trim(\App\Support\Phone::englishDigits((string) $request->query('q', '')));
        $status = array_key_exists((string) $request->query('status'), HistoryImport::STATUSES) ? $request->query('status') : '';
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? $request->query('sort') : 'new';
        $phone = \App\Support\Phone::normalize($q);

        $orders = DB::table('customer_past_orders as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->where('p.customer_import_id', $import)
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $phone
                ? $s->where('c.primary_phone', $phone)->orWhereIn('c.id', DB::table('customer_phones')->where('phone', $phone)->select('customer_id'))
                : $s->where('c.name', 'like', '%'.$q.'%')->orWhere('p.cn', strtoupper($q))->orWhere('p.external_no', $q)->orWhere('p.address', 'like', '%'.$q.'%')->orWhere('p.items', 'like', '%'.$q.'%')))
            ->when($status, fn ($w) => $w->where('p.status', $status))
            ->when($sort === 'new', fn ($w) => $w->orderByDesc('p.ordered_at'))->when($sort === 'old', fn ($w) => $w->orderBy('p.ordered_at'))
            ->when($sort === 'high', fn ($w) => $w->orderByDesc('p.amount'))->when($sort === 'low', fn ($w) => $w->orderBy('p.amount'))
            ->when($sort === 'name', fn ($w) => $w->orderBy('c.name'))->orderBy('p.id')
            ->select(['c.id', 'c.name', 'c.primary_phone', 'p.external_no', 'p.cn', 'p.ordered_at', 'p.amount', 'p.status', 'p.items', 'p.address'])
            ->paginate(25)->withQueryString();
        $result = json_decode((string) $row->result, true) ?: [];

        return view('customers.history-show', [
            'import' => $row,
            'result' => $result,
            'orders' => $orders,
            'problems' => $result['problems'] ?? [],
            'filters' => ['q' => $q, 'status' => $status, 'sort' => $sort],
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


}
