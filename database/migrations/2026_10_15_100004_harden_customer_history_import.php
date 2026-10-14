<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * History import, made safer:
 * - row_key: a row with neither order number nor CN is known again by its
 *   phone, date, amount and products, so a second upload does not double it;
 * - changes: what an import overwrote on parcels brought in earlier, so Undo
 *   can put it back;
 * - default_medium: remembered for the next file.
 * Imported parcels left on customers that were merged away move to the
 * customer they were merged into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_past_orders', function (Blueprint $table) {
            $table->string('row_key', 40)->nullable()->after('cn')->index();
        });
        Schema::table('customer_imports', function (Blueprint $table) {
            $table->longText('changes')->nullable()->after('result');
            $table->string('default_medium', 20)->nullable()->after('values');
        });

        // Follow merges until every parcel sits on a live customer (a chain of merges takes several passes).
        for ($i = 0; $i < 10; $i++) {
            $moved = DB::table('customer_past_orders as p')->join('customers as c', 'c.id', '=', 'p.customer_id')
                ->whereNotNull('c.merged_into_id')->get(['p.id', 'c.merged_into_id']);
            if ($moved->isEmpty()) {
                break;
            }
            foreach ($moved->groupBy('merged_into_id') as $to => $rows) {
                DB::table('customer_past_orders')->whereIn('id', $rows->pluck('id'))->update(['customer_id' => $to]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customer_imports', function (Blueprint $table) {
            $table->dropColumn(['changes', 'default_medium']);
        });
        Schema::table('customer_past_orders', function (Blueprint $table) {
            $table->dropIndex(['row_key']);
            $table->dropColumn('row_key');
        });
    }
};
