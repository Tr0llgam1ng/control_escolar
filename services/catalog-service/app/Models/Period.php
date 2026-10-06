<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'grades_open_from', 'grades_open_until', 'is_current'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'grades_open_from' => 'date:Y-m-d',
            'grades_open_until' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    protected $appends = ['grade_capture_open'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /** RN-02: grade capture window defined by Control Escolar (null = no limit). */
    public function getGradeCaptureOpenAttribute(): bool
    {
        $today = today();

        return (! $this->grades_open_from || $today->gte($this->grades_open_from))
            && (! $this->grades_open_until || $today->lte($this->grades_open_until));
    }

    public function makeCurrent(): void
    {
        static::query()->whereKeyNot($this->id)->update(['is_current' => false]);
        $this->forceFill(['is_current' => true])->save();
    }
}
