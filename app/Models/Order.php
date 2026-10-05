<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status changes go ONLY through OrderStateMachine; item/price/address
 * changes only through OrderEditor. Money columns are never typed.
 */
class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'utm' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'advance_verified' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'refund_due' => 'decimal:2',
            'edited_after_pack' => 'boolean',
            'is_duplicate_flag' => 'boolean',
            'verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'pickup_date' => 'date',
            'hold_expected_date' => 'date',
            'had_setback' => 'boolean',
            'queue_since' => 'datetime',
            'assigned_at' => 'datetime',
            'action_due_at' => 'datetime',
            'next_call_at' => 'datetime',
            'packing_sent_at' => 'datetime',
            'packing_started_at' => 'datetime',
            'packed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function packer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packer_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function holdReason(): BelongsTo
    {
        return $this->belongsTo(StatusReason::class, 'hold_reason_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('id');
    }

    /**
     * Orders a user may see for orders.view: all, or own. "Own" also includes
     * unclaimed orders still waiting to be taken, so agents can claim them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->permissionScope('orders.view')) {
            'all' => $query,
            'own', 'team' => $query->where(fn ($q) => $q->where('orders.moderator_id', $user->id)
                ->orWhere(fn ($q) => $q->whereNull('orders.moderator_id')->whereIn('orders.status_id', OrderStatus::idsFor(['new', 'record_verified'])))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** RED: edited after packing, box is wrong. EDITED: edited and repacked. */
    public function packMark(): ?string
    {
        if ($this->packed_version === null) {
            return null;
        }
        if ($this->packed_version < $this->current_version) {
            return 'repack';
        }

        return $this->edited_after_pack ? 'edited' : null;
    }
}
