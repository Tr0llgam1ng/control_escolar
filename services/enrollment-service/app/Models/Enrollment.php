<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    protected $fillable = ['course_id', 'student_id', 'final_grade', 'status', 'enrolled_by'];

    protected function casts(): array
    {
        return ['course_id' => 'integer', 'student_id' => 'integer', 'final_grade' => 'float'];
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class)->orderBy('unit');
    }

    public static function passingGrade(): float
    {
        return (float) config('school.passing_grade', 7);
    }

    /** RN-03: final grade = average of all units once every unit is graded. */
    public function recalculate(int $unitsCount): void
    {
        $grades = $this->grades()->where('unit', '<=', $unitsCount)->get();

        if ($unitsCount > 0 && $grades->count() >= $unitsCount) {
            $this->final_grade = round((float) $grades->avg('score'), 2);
            $this->status = $this->final_grade >= self::passingGrade() ? 'approved' : 'failed';
        } else {
            $this->final_grade = null;
            $this->status = 'in_progress';
        }

        $this->save();
    }
}
