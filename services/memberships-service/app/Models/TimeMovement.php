<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ledger of time: included time used, extra time bought / used / refunded. */
class TimeMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'included_seconds' => 'integer', 'extra_seconds' => 'integer', 'source_id' => 'integer'];
    }
}
