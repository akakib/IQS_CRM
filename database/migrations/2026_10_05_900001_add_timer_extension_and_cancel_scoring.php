<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "+5 minutes" on the action timer (once per order, recorded with who and
 * when), and the owner's rule that every cancelled order costs its
 * moderator a point whatever the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('timer_extended_at')->nullable();
            $table->foreignId('timer_extended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['timer_extended_by', 'timer_extended_at']);
        });

        // Existing installs: the cancel rule loses its "sales mistake only" condition.
        if (Schema::hasTable('point_rules')) {
            $rule = DB::table('point_rules')->where('trigger_key', 'order_cancelled')->where('name', 'Cancelled by a sales mistake')->first();
            if ($rule) {
                DB::table('point_rule_conditions')->where('rule_id', $rule->id)->delete();
                DB::table('point_rules')->where('id', $rule->id)->update(['name' => 'Order cancelled (any reason)', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['timer_extended_by', 'timer_extended_at']);
            $table->dropConstrainedForeignId('timer_extended_by');
            $table->dropColumn('timer_extended_at');
        });
    }
};
