<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    protected $fillable = ['customer_id', 'address_line', 'district', 'thana', 'steadfast_thana_id', 'zone_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function oneLine(): string
    {
        return collect([$this->address_line, $this->thana, $this->district])->filter()->join(', ');
    }
}
