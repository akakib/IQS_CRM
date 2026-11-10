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
    /**
     * Customers a person may list and open (customers.view scope). Own: customers of the orders they may see
     * (Order::visibleTo), orders they confirmed, customers they created, and their call-list rows. A full number
     * typed in a search opens any customer for that session (CustomerController::unlock).
     */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        return match ($user->permissionScope('customers.view')) {
            'all' => $query,
            'own', 'team' => $query->where(fn ($q) => $q->whereIn('customers.id', Order::visibleTo($user)->select('orders.customer_id'))
                ->orWhereIn('customers.id', \Illuminate\Support\Facades\DB::table('orders')->select('customer_id')->where('confirmed_by', $user->id))
                ->orWhere('customers.created_by', $user->id)
                ->orWhereIn('customers.id', \Illuminate\Support\Facades\DB::table('call_list_items')->select('customer_id')->where('user_id', $user->id)->whereNotNull('customer_id'))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** May this person open this customer's page? */
    public static function canOpen(User $user, int $id): bool
    {
        return $user->permissionScope('customers.view') === 'all'
            || in_array($id, session('customers.unlocked', []), true)
            || self::visibleTo($user)->whereKey($id)->exists();
    }

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
