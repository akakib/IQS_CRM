<?php

namespace App\Http\Controllers;

use App\Services\Catalog\WooCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Website CSV import. The progress page calls step() repeatedly; each call
 * imports a few hundred rows, so no background worker is needed.
 */
class ProductImportController extends Controller
{
    public function index(): View
    {
        return view('products.import', [
            'websiteApi' => app(\App\Services\Catalog\Store\WooApi::class)->configured(),
            'imports' => DB::table('product_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
                ->orderByDesc('i.id')->limit(10)
                ->get(['i.id', 'i.source', 'i.file_name', 'i.status', 'i.rows_done', 'i.rows_total', 'i.created_count', 'i.updated_count', 'i.skipped_count', 'i.created_at', 'u.name as user']),
        ]);
    }

    public function store(Request $request, WooCsvImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);

        $file = $request->file('file');
        $path = $file->store('imports', 'local');

        $id = DB::table('product_imports')->insertGetId([
            'user_id' => $request->user()->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'status' => 'pending',
            'rows_total' => $importer->countRows(Storage::disk('local')->path($path)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('products.import.show', $id);
    }

    public function show(int $import): View
    {
        $row = DB::table('product_imports')->where('id', $import)->first();
        abort_unless($row, 404);

        return view('products.import-show', ['import' => $row]);
    }

    public function step(int $import, WooCsvImporter $importer, \App\Services\Catalog\WooApiImporter $api): JsonResponse
    {
        $source = DB::table('product_imports')->where('id', $import)->value('source');
        abort_unless($source, 404);
        @set_time_limit(120);

        $row = $source === 'api' ? $api->step($import) : $importer->step($import);

        return response()->json([
            'status' => $row->status,
            'rows_done' => (int) $row->rows_done,
            'rows_total' => (int) $row->rows_total,
            'created' => (int) $row->created_count,
            'updated' => (int) $row->updated_count,
            'skipped' => (int) $row->skipped_count,
            'errors' => array_slice(json_decode($row->errors ?? '[]', true) ?: [], 0, 50),
        ]);
    }
}
