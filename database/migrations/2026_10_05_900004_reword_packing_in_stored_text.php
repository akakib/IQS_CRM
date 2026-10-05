<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Text already saved (order history, notifications, reason and rule names)
 * said "packing"; the module is called Packaging now.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'order_notes' => ['body'], 'app_notifications' => ['title', 'body', 'link_url'], 'point_rules' => ['name'],
        'scan_logs' => ['message'], 'status_reasons' => ['label_en'],
    ];

    public function up(): void
    {
        $this->reword(['packing' => 'packaging', 'Packing' => 'Packaging']);
    }

    public function down(): void
    {
        $this->reword(['packaging' => 'packing', 'Packaging' => 'Packing']);
    }

    private function reword(array $words): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                foreach ($words as $from => $to) {
                    DB::table($table)->where($column, 'like', "%{$from}%")->update([$column => DB::raw("REPLACE({$column}, '{$from}', '{$to}')")]);
                }
            }
        }
    }
};
