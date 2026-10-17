<?php

use App\Support\Units;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much one item holds (grams or ml), so sales add up in kg and litres. Read from the names
 * ("500g", "3kg", "600ml"); the items whose name says no size stay empty and count only by pieces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('size_qty', 12, 3)->nullable()->after('pack_qty');
            $table->string('size_unit', 2)->nullable()->after('size_qty'); // g | ml
        });

        DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->orderBy('v.id')
            ->select(['v.id', 'v.name', 'p.name as product'])->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if ($size = Units::sizeFromName($row->name === 'Default' ? null : $row->name, $row->product)) {
                        DB::table('product_variants')->where('id', $row->id)->update(['size_qty' => $size[0], 'size_unit' => $size[1]]);
                    }
                }
            }, 'v.id', 'id');
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['size_qty', 'size_unit']);
        });
    }
};
