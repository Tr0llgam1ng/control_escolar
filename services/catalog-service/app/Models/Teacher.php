<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    protected $fillable = ['user_id', 'employee_number', 'name', 'email', 'department', 'phone'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
