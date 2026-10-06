<?php

return [
    // RN-03: grading scale 0-10, the institution defines the passing grade.
    'passing_grade' => (float) env('SCHOOL_PASSING_GRADE', 7),
];
