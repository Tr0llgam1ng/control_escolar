<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    protected $fillable = ['plan_id', 'feature', 'enabled', 'value'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'value' => 'integer'];
    }
}
