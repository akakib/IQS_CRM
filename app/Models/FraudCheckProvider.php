<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraudCheckProvider extends Model
{
    protected $fillable = ['system_key', 'name', 'driver_class', 'credentials', 'cache_hours', 'is_active', 'sort_order'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean'];
    }
}
