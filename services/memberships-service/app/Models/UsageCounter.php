<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    protected $fillable = ['user_id', 'feature', 'cycle_start', 'used'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'used' => 'integer', 'cycle_start' => 'datetime'];
    }
}
