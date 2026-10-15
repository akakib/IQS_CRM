<?php

use App\Services\Customers\HistoryImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Values imported before labels were stripped ("Address: House 12", "Name: Rahima"): cleaned once. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customer_past_orders')->whereNotNull('customer_import_id')->where(fn ($q) => $q->where('address', 'like', '%:%')->orWhere('items', 'like', '%:%'))
            ->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $r) {
                    $clean = ['address' => $r->address === null ? null : (HistoryImport::unlabel($r->address) ?: null), 'items' => $r->items === null ? null : (HistoryImport::unlabel($r->items) ?: null)];
                    if ($clean['address'] !== $r->address || $clean['items'] !== $r->items) {
                        DB::table('customer_past_orders')->where('id', $r->id)->update($clean);
                    }
                }
            });
        DB::table('customers')->whereNotNull('customer_import_id')->where('name', 'like', '%:%')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                if (($name = HistoryImport::unlabel($r->name)) !== '' && $name !== $r->name) {
                    DB::table('customers')->where('id', $r->id)->update(['name' => $name]);
                }
            }
        });
        DB::table('call_list_items')->where('name', 'like', '%:%')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                if (($name = HistoryImport::unlabel($r->name)) !== '' && $name !== $r->name) {
                    DB::table('call_list_items')->where('id', $r->id)->update(['name' => $name]);
                }
            }
        });
    }

    public function down(): void {}
};
