<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = ['enrollment_id', 'unit', 'score', 'captured_by'];

    protected $hidden = ['created_at'];

    protected function casts(): array
    {
        return ['unit' => 'integer', 'score' => 'float'];
    }
}
