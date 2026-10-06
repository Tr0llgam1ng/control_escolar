<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** RF-26: audit trail of every grade capture / change. */
class GradeLog extends Model
{
    protected $fillable = ['enrollment_id', 'unit', 'old_score', 'new_score', 'user_id'];
}
