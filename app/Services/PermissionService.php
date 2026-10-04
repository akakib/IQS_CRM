<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\PermissionMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves what a user may do.
 *
 * Rules: Owner role = everything. Otherwise the union of active roles (widest
 * data scope wins), then per-user overrides: allow adds, deny removes, deny
 * always wins. No row anywhere = not allowed.
 *
 * Cost: at most 3 small queries on a cache miss, 1 cache read per request
 * after that, and zero per check (memoised per request). The cache key
 * carries a global version that bump() increments on any role/permission
 * change, and the TTL ends at the user's next start/expiry time, so a
 * temporary grant never outlives itself.
 */
class PermissionService
{
    /** @var array<int, PermissionMap> */
    private array $memo = [];

    public function for(User $user): PermissionMap
    {
        return $this->memo[$user->id] ??= $this->cached($user);
    }

    /** Call after any change to roles, permissions, masks or assignments. */
    public function bump(): void
    {
        Cache::forever('permissions:version', $this->version() + 1);
        $this->memo = [];
    }

    private function version(): int
    {
        return (int) Cache::get('permissions:version', 1);
    }

    private function cached(User $user): PermissionMap
    {
        $key = 'permissions:'.$this->version().':'.$user->id;

        if (is_array($hit = Cache::get($key))) {
            return PermissionMap::fromArray($hit);
        }

        [$map, $ttl] = $this->resolve($user);
        Cache::put($key, $map->toArray(), $ttl);

        return $map;
    }

    /** @return array{0: PermissionMap, 1: int} map and seconds it stays valid */
    private function resolve(User $user): array
    {
        $now = now();
        $ttl = (int) config('permissions.cache_ttl', 3600);

        // A row is active when started and not expired. Every start/expiry in
        // the future shortens the cache lifetime to that moment.
        $active = function (object $row) use ($now, &$ttl): bool {
            foreach ([$row->starts_at, $row->expires_at] as $t) {
                if ($t && ($t = Carbon::parse($t))->gt($now)) {
                    $ttl = min($ttl, max(1, (int) ceil($now->diffInSeconds($t))));
                }
            }

            return (! $row->starts_at || Carbon::parse($row->starts_at)->lte($now))
                && (! $row->expires_at || Carbon::parse($row->expires_at)->gt($now));
        };

        // Query 1: all of the user's role assignments with their grants.
        // A user has a handful of rows, so filtering in PHP is cheaper than
        // a second round trip for the boundaries.
        $roleRows = DB::table('user_roles as ur')
            ->join('roles as r', fn ($j) => $j->on('r.id', '=', 'ur.role_id')->whereNull('r.deleted_at'))
            ->leftJoin('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->leftJoin('permissions as p', fn ($j) => $j->on('p.id', '=', 'rp.permission_id')->where('p.is_active', true))
            ->where('ur.user_id', $user->id)
            ->select(['ur.id as assignment_id', 'ur.starts_at', 'ur.expires_at', 'r.id as role_id', 'r.system_key', 'p.key', 'rp.data_scope'])
            ->get();

        // Query 2: per-user overrides.
        $overrides = DB::table('user_permissions as up')
            ->join('permissions as p', fn ($j) => $j->on('p.id', '=', 'up.permission_id')->where('p.is_active', true))
            ->where('up.user_id', $user->id)
            ->select(['up.starts_at', 'up.expires_at', 'p.key', 'up.effect', 'up.data_scope'])
            ->get();

        $activeAssignments = [];
        foreach ($roleRows->unique('assignment_id') as $row) {
            if ($active($row)) {
                $activeAssignments[$row->assignment_id] = true;
            }
        }
        $roleRows = $roleRows->filter(fn ($row) => isset($activeAssignments[$row->assignment_id]));
        $overrides = $overrides->filter($active);

        if ($roleRows->contains('system_key', Role::OWNER)) {
            return [new PermissionMap(true, [], []), $ttl];
        }

        $rank = PermissionMap::SCOPE_RANK;
        $scopes = [];
        foreach ($roleRows as $row) {
            if ($row->key && $rank[$row->data_scope] > (isset($scopes[$row->key]) ? $rank[$scopes[$row->key]] : 0)) {
                $scopes[$row->key] = $row->data_scope;
            }
        }
        foreach ($overrides as $o) {
            if ($o->effect === 'allow') {
                $scopes[$o->key] = $o->data_scope ?? 'all';
            }
        }
        foreach ($overrides as $o) {
            if ($o->effect === 'deny') {
                unset($scopes[$o->key]);
            }
        }

        // Query 3: masks. A field is hidden only if EVERY active role hides
        // it, so adding a role can only reveal more, never hide more.
        $roleIds = $roleRows->pluck('role_id')->unique()->values();
        // No role at all = every sensitive field hidden.
        $masks = $roleIds->isEmpty() ? array_fill_keys(config('permissions.field_masks', []), true) : [];
        if ($roleIds->isNotEmpty()) {
            $counts = DB::table('role_field_masks')->whereIn('role_id', $roleIds)
                ->selectRaw('field, COUNT(*) as n')->groupBy('field')->pluck('n', 'field');
            foreach ($counts as $field => $n) {
                if ((int) $n === $roleIds->count()) {
                    $masks[$field] = true;
                }
            }
        }

        return [new PermissionMap(false, $scopes, $masks), $ttl];
    }
}
