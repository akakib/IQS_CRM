<?php

namespace App\Models;

use App\Enums\EmploymentType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\PermissionService;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'employment_type',
        'work_location_id',
        'telegram_user_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'employment_type' => EmploymentType::class,
        ];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'work_location_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['id', 'starts_at', 'expires_at', 'assigned_by', 'reason'])
            ->withTimestamps();
    }

    public function hasPermission(string $key): bool
    {
        return app(PermissionService::class)->for($this)->allows($key);
    }

    /** own | team | all, or null when not allowed. */
    public function permissionScope(string $key): ?string
    {
        return app(PermissionService::class)->for($this)->scope($key);
    }

    public function isOwner(): bool
    {
        return app(PermissionService::class)->for($this)->isOwner;
    }

    public function canSeeField(string $field): bool
    {
        return ! app(PermissionService::class)->for($this)->hides($field);
    }
}
