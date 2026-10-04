<?php

namespace App\Console\Commands;

use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredAccess extends Command
{
    protected $signature = 'permissions:prune-expired';

    protected $description = 'Remove temporary role assignments and permission overrides that have expired';

    public function handle(PermissionService $permissions): int
    {
        // Access already ends at expires_at at check time; this only tidies up.
        $roles = DB::table('user_roles')->where('expires_at', '<=', now())->delete();
        $overrides = DB::table('user_permissions')->where('expires_at', '<=', now())->delete();

        if ($roles + $overrides > 0) {
            $permissions->bump();
        }

        $this->info("Removed {$roles} expired roles and {$overrides} expired overrides.");

        return self::SUCCESS;
    }
}
