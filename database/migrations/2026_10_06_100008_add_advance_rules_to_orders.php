<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advance rules (2026-10-06): a weak or new Steadfast history needs the
 * delivery charge in advance (advance_required). A moderator can ask an admin
 * to let it go without (advance_waiver_*). The default rules change to:
 * 75% or more on 3+ parcels = call; below 75% or fewer than 3 parcels = advance;
 * fully paid = call too (a call can still add items).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('advance_required', 12, 2)->nullable();
            $table->timestamp('advance_waiver_requested_at')->nullable();
            $table->foreignId('advance_waiver_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('advance_waiver_note', 300)->nullable();
            $table->foreignId('advance_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('advance_waived_at')->nullable();
        });

        // Existing rule sets (the test server) get the same change, matched by the default names.
        if (! Schema::hasTable('verification_rules') || ! DB::table('verification_rules')->exists()) {
            return;
        }
        $steadfast = DB::table('fraud_check_providers')->where('system_key', 'steadfast')->value('id');
        $now = now();
        DB::table('verification_rules')->where('name', 'Fully prepaid')->update(['outcome' => 'record_verified', 'updated_at' => $now]);
        $good = DB::table('verification_rules')->where('name', 'Good Steadfast history')->value('id');
        if ($good) {
            DB::table('verification_rule_conditions')->where('rule_id', $good)->where('field', 'provider_success_rate')->update(['value' => '75']);
        }
        DB::table('verification_rules')->whereIn('name', ['New customer, small order', 'New customer, big order'])->update(['is_active' => false, 'updated_at' => $now]);
        foreach ([
            ['Weak Steadfast history', 45, [['provider_success_rate', '<', '75'], ['provider_total_parcels', '>=', '3']]],
            ['New to Steadfast (fewer than 3 parcels)', 50, [['provider_total_parcels', '<', '3']]],
        ] as [$name, $priority, $conditions]) {
            if (DB::table('verification_rules')->where('name', $name)->exists()) {
                continue;
            }
            $id = DB::table('verification_rules')->insertGetId([
                'name' => $name, 'priority' => $priority, 'outcome' => 'hold_for_advance', 'applies_to_channel' => 'all',
                'stop_on_match' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($conditions as [$field, $op, $value]) {
                DB::table('verification_rule_conditions')->insert(['rule_id' => $id, 'field' => $field, 'provider_id' => $steadfast, 'operator' => $op, 'value' => $value]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_waiver_requested_by');
            $table->dropConstrainedForeignId('advance_waived_by');
            $table->dropColumn(['advance_required', 'advance_waiver_requested_at', 'advance_waiver_note', 'advance_waived_at']);
        });
    }
};
