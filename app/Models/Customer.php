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
        'orders_count', 'delivered_count', 'returned_count', 'iqs_orders_count', 'iqs_delivered_count', 'iqs_returned_count', 'first_order_at', 'marketing_consent', 'consent_at',
        'merged_into_id', 'created_by', 'do_not_call_at',
    ];

    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'consent_at' => 'datetime',
            'first_order_at' => 'datetime',
            'do_not_call_at' => 'datetime',
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

    /** No delivered or returned parcel in what IQS itself saw (imported files do not count). */
    public function isNew(): bool
    {
        return (int) $this->iqs_delivered_count === 0 && (int) $this->iqs_returned_count === 0;
    }

    /** Orders brought in from files (history only): the whole count less what IQS saw. */
    public function importedCounts(): array
    {
        return [
            'orders' => max(0, (int) $this->orders_count - (int) $this->iqs_orders_count),
            'delivered' => max(0, (int) $this->delivered_count - (int) $this->iqs_delivered_count),
            'returned' => max(0, (int) $this->returned_count - (int) $this->iqs_returned_count),
        ];
    }
}
