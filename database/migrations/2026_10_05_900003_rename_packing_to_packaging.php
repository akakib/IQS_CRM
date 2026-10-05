<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The module is called Packaging everywhere (it was Packing): columns, stored
 * keys and permission keys follow. Role grants survive because permissions
 * are renamed in place, not re-created.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('packing', 'packaging');
    }

    public function down(): void
    {
        $this->rename('packaging', 'packing');
    }

    private function rename(string $from, string $to): void
    {
        Schema::table('orders', function (Blueprint $table) use ($from, $to) {
            $table->renameColumn("{$from}_sent_at", "{$to}_sent_at");
            $table->renameColumn("{$from}_started_at", "{$to}_started_at");
        });

        // Enum values: allow both, move the rows, then drop the old one.
        $blame = ['none', 'sales', 'verification', 'dispatch', 'courier', 'customer'];
        Schema::table('status_reasons', fn (Blueprint $t) => $t->enum('blame_stage', [...$blame, $from, $to])->default('none')->change());
        DB::table('status_reasons')->where('blame_stage', $from)->update(['blame_stage' => $to]);
        Schema::table('status_reasons', fn (Blueprint $t) => $t->enum('blame_stage', ['none', 'sales', 'verification', $to, 'dispatch', 'courier', 'customer'])->default('none')->change());

        Schema::table('scan_logs', fn (Blueprint $t) => $t->enum('station', [$from, $to, 'handover'])->change());
        DB::table('scan_logs')->where('station', $from)->update(['station' => $to]);
        Schema::table('scan_logs', fn (Blueprint $t) => $t->enum('station', [$to, 'handover'])->change());

        foreach (DB::table('permissions')->where('module', $from)->get(['id', 'action']) as $p) {
            DB::table('permissions')->where('id', $p->id)->update(['key' => "{$to}.{$p->action}", 'module' => $to]);
        }
        DB::table('point_rule_conditions')->where('field', 'blame')->where('value', $from)->update(['value' => $to]);
    }
};
