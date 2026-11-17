<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'credits_cost' => 'integer', 'result' => 'array'];
    }
}
