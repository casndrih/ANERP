<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'credit_hold' => 'boolean'];
    }
}
