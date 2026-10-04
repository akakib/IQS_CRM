<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'primary_phone', 'messenger_psid', 'whatsapp_number', 'risk_level', 'blocked_reason', 'note',
        'orders_count', 'delivered_count', 'returned_count', 'first_order_at', 'marketing_consent', 'consent_at',
        'merged_into_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'consent_at' => 'datetime',
            'first_order_at' => 'datetime',
        ];
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function fraudChecks(): HasMany
    {
        return $this->hasMany(CustomerFraudCheck::class);
    }

    /** No delivered or returned parcel anywhere in our own history. */
    public function isNew(): bool
    {
        return $this->delivered_count === 0 && $this->returned_count === 0;
    }
}
