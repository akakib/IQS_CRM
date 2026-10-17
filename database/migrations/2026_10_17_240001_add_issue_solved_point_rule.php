<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** +4 to whoever solves a delivery issue of a colleague's order (Settings > Points can change it). */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('point_rules') || DB::table('point_rules')->where('trigger_key', 'issue_solved')->exists()) {
            return;
        }
        if (! DB::table('point_rules')->exists()) {
            return; // a fresh database: PointsSeeder adds it with the rest
        }
        $id = DB::table('point_rules')->insertGetId([
            'trigger_key' => 'issue_solved', 'name' => "Solved a delivery issue of a colleague's order", 'points' => 4, 'recipient' => 'actor',
            'settle_on' => 'immediate', 'requires_delivery' => false, 'is_active' => true, 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('point_rule_conditions')->insert(['rule_id' => $id, 'field' => 'others_order', 'operator' => '=', 'value' => 'true']);
    }

    public function down(): void
    {
        $ids = DB::table('point_rules')->where('trigger_key', 'issue_solved')->pluck('id');
        DB::table('point_rule_conditions')->whereIn('rule_id', $ids)->delete();
        DB::table('point_rules')->whereIn('id', $ids)->delete();
    }
};
