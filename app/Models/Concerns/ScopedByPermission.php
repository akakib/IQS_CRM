<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies a permission's data scope to a query:
 *   Order::visibleTo($user, 'orders.view')->...
 * all = no filter, own/team = rows whose owner column is the user
 * (team falls back to own until teams exist), none = no rows.
 */
trait ScopedByPermission
{
    protected function ownerColumn(): string
    {
        return 'owner_id';
    }

    public function scopeVisibleTo(Builder $query, User $user, string $permission): Builder
    {
        return match ($user->permissionScope($permission)) {
            'all' => $query,
            'own', 'team' => $query->where($this->qualifyColumn($this->ownerColumn()), $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
