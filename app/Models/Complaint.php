<?php

namespace App\Models;

use App\Models\Concerns\ScopedByPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    use ScopedByPermission;

    public const SOURCES = ['phone', 'messenger', 'whatsapp', 'website', 'rider', 'other'];

    public const STAGES = ['none', 'sales', 'verification', 'packing', 'dispatch', 'courier', 'customer'];

    public const RESOLUTIONS = ['solved', 'refunded', 'replacement', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'sla_due_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    /** "Own" complaints are the ones assigned to me. */
    protected function scopeUserColumn(): string
    {
        return 'assigned_to';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StatusReason::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ComplaintPhoto::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->sla_due_at !== null && $this->sla_due_at->isPast();
    }
}
