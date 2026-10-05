<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The person working an order is its "moderator" (the word "owner" is kept
 * for the business Owner role only). Fresh installs already get the new
 * names from the create migrations; this upgrades databases built before
 * the rename. Raw SQL with CHANGE so it also runs on MariaDB 10.4 (local XAMPP).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'owner_id') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE orders DROP FOREIGN KEY orders_owner_id_foreign');
        DB::statement('ALTER TABLE orders DROP INDEX orders_owner_id_status_id_index');
        DB::statement('ALTER TABLE orders CHANGE owner_id moderator_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE orders ADD INDEX orders_moderator_id_status_id_index (moderator_id, status_id)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_moderator_id_foreign FOREIGN KEY (moderator_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->renameEnum('order_assignments', 'role', ['owner' => 'moderator'], ['moderator', 'temporary']);
        $this->renameEnum('point_rules', 'recipient', ['order_owner' => 'order_moderator', 'previous_owner' => 'previous_moderator'], ['order_moderator', 'actor', 'packer', 'previous_moderator']);
        $this->renameEnum('notification_rules', 'target', ['order_owner' => 'order_moderator'], ['role', 'user', 'order_moderator', 'actor_manager']);

        DB::table('status_reasons')->where('system_key', 'owner_error')->update(['system_key' => 'moderator_error']);
        DB::table('status_reasons')->where('label_en', 'Owner on leave or off shift')->update(['label_en' => 'Moderator on leave or off shift']);
        DB::table('status_reasons')->where('label_en', 'Owner could not handle it')->update(['label_en' => 'Moderator could not handle it']);

        // Stored text: point rule snapshots and reassignment notes.
        DB::statement("UPDATE point_ledger SET rule_snapshot = REPLACE(REPLACE(rule_snapshot, '\"order_owner\"', '\"order_moderator\"'), '\"previous_owner\"', '\"previous_moderator\"')");
        DB::statement("UPDATE order_notes SET meta = REPLACE(meta, 'previous_owner_id', 'previous_moderator_id') WHERE meta LIKE '%previous_owner_id%'");
        DB::statement("UPDATE order_notes SET body = REPLACE(body, 'Owner changed from', 'Reassigned from') WHERE note_type = 'assignment' AND body LIKE 'Owner changed from%'");
    }

    /** Widen the enum, move the rows, then narrow it to the new values. */
    private function renameEnum(string $table, string $column, array $map, array $final): void
    {
        $quote = fn (array $v) => implode(',', array_map(fn ($x) => "'".$x."'", $v));
        DB::statement("ALTER TABLE {$table} MODIFY {$column} ENUM(".$quote(array_unique(array_merge(array_keys($map), $final))).') NOT NULL');
        foreach ($map as $old => $new) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }
        DB::statement("ALTER TABLE {$table} MODIFY {$column} ENUM(".$quote($final).') NOT NULL');
    }

    public function down(): void
    {
        // One-way rename: the old names are not coming back.
    }
};
