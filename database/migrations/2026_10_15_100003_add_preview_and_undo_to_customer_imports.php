<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An import runs only with the exact mapping that was checked (preview_hash),
 * and can be taken back: its orders, and the customers it created that have
 * nothing else in IQS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_imports', function (Blueprint $table) {
            $table->string('preview_hash', 64)->nullable()->after('result');
            $table->timestamp('undone_at')->nullable()->after('done_at');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('customer_import_id')->nullable()->constrained()->nullOnDelete();
        });
        // Imports done before this column: their new customers are the ones created by that person while it ran.
        foreach (DB::table('customer_imports')->whereNotNull('done_at')->get() as $i) {
            DB::table('customers')->whereNull('customer_import_id')->where('created_by', $i->created_by)
                ->whereBetween('created_at', [$i->created_at, $i->done_at])
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('customer_past_orders as p')->whereColumn('p.customer_id', 'customers.id')->where('p.customer_import_id', $i->id))
                ->update(['customer_import_id' => $i->id]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_import_id');
        });
        Schema::table('customer_imports', function (Blueprint $table) {
            $table->dropColumn(['preview_hash', 'undone_at']);
        });
    }
};
