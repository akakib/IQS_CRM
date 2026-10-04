<?php

namespace App\Console\Commands;

use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Copy config/permissions.php into the permissions table (new ones start not allowed for every role)';

    public function handle(PermissionService $permissions): int
    {
        $now = now();
        $rows = [];
        $order = 0;

        foreach (config('permissions.modules') as $module => $actions) {
            foreach ($actions as $action) {
                $rows[] = [
                    'key' => "{$module}.{$action}",
                    'module' => $module,
                    'action' => $action,
                    'sort_order' => ++$order,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // One statement; existing grants are untouched, new keys get no role rows.
        DB::table('permissions')->upsert($rows, ['key'], ['module', 'action', 'sort_order', 'is_active', 'updated_at']);

        // Keys removed from config are deactivated, never deleted (grants stay for history).
        $retired = DB::table('permissions')->whereNotIn('key', array_column($rows, 'key'))
            ->where('is_active', true)->update(['is_active' => false, 'updated_at' => $now]);

        $permissions->bump();
        $this->info(count($rows).' permissions synced, '.$retired.' retired.');

        return self::SUCCESS;
    }
}
