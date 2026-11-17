<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyRoom extends Model
{
    protected $fillable = ['name', 'location', 'capacity', 'equipment', 'is_active'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean'];
    }
}
