<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_trx_id' => 'boolean', 'is_active' => 'boolean'];
    }
}
