<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. */
class CustomerFraudCheck extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_response' => 'array', 'checked_at' => 'datetime', 'success_rate' => 'decimal:2'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FraudCheckProvider::class, 'provider_id');
    }
}
