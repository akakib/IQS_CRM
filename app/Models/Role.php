<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use LogsActivity, SoftDeletes;

    public const OWNER = 'owner';

    protected $fillable = ['name', 'system_key', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot(['data_scope', 'location_ids']);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withPivot(['starts_at', 'expires_at']);
    }

    public function isOwner(): bool
    {
        return $this->system_key === self::OWNER;
    }
}
