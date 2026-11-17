<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeBalance extends Model
{
    protected $fillable = ['user_id', 'module', 'extra_seconds'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'extra_seconds' => 'integer'];
    }
}
