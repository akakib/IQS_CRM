<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Website names came in as HTML ("Choc &amp; Rose"): store them as plain text, the way they read. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products' => 'name', 'product_variants' => 'name', 'order_items' => 'name_snapshot'] as $table => $column) {
            DB::table($table)->where($column, 'like', '%&%;%')->orderBy('id')->select(['id', $column])
                ->chunkById(500, function ($rows) use ($table, $column) {
                    foreach ($rows as $r) {
                        $plain = html_entity_decode((string) $r->{$column}, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($plain !== $r->{$column}) {
                            DB::table($table)->where('id', $r->id)->update([$column => $plain]);
                        }
                    }
                });
        }
    }

    public function down(): void {}
};
