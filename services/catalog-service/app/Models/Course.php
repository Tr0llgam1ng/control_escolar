<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A class section: subject + group + teacher + period ("la habitación" of the reservation example). */
class Course extends Model
{
    protected $fillable = ['subject_id', 'period_id', 'teacher_id', 'group_name', 'classroom', 'capacity', 'units_count', 'status'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'units_count' => 'integer'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
