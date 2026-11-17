<?php

/*
| Memberships Service settings. Plan limits (quotas, included hours/minutes, discounts) live in the
| database (plan_features) so Control Escolar can edit them; these are the base prices of the modules.
*/
return [
    'timezone' => env('APP_TIMEZONE', 'America/Mexico_City'),

    // Credits module 1: tutoring with a teacher. Base price per session, before the plan discount.
    'tutoring' => [
        'base_cost' => (int) env('MEMBERSHIPS_TUTORING_COST', 60),
        'duration_minutes' => 60,
        'min_hours_ahead' => 12,   // a session is requested at least 12 h before
        'max_days_ahead' => 30,
        'refund_hours' => 12,      // cancelling 12 h or more before → full refund
        'opens' => 8,              // sessions between 08:00 and 20:00
        'closes' => 20,
    ],

    // Credits module 2: academic analysis. Its price per plan is the feature analytics.cost.
    'analytics' => [
        'passing_grade' => 7,
    ],

    // Time module 1: study rooms booked by the hour.
    'study_rooms' => [
        'opens' => 7,
        'closes' => 21,
        'slot_minutes' => 30,
        'extra_hour_cost' => (int) env('MEMBERSHIPS_EXTRA_HOUR_COST', 25),   // credits per extra hour
    ],

    // Time module 2: digital library, used by the minute (session with a stopwatch).
    'library' => [
        'extra_block_minutes' => 30,
        'extra_block_cost' => (int) env('MEMBERSHIPS_EXTRA_BLOCK_COST', 10), // credits per 30 extra minutes
        'resources' => [
            'ieee' => ['name' => 'IEEE Xplore', 'description' => 'Artículos y estándares de ingeniería y computación.'],
            'springer' => ['name' => 'SpringerLink', 'description' => 'Libros y revistas científicas.'],
            'oreilly' => ['name' => "O'Reilly Learning", 'description' => 'Libros técnicos de programación y bases de datos.'],
            'ebsco' => ['name' => 'EBSCO Academic', 'description' => 'Base de datos multidisciplinaria.'],
        ],
    ],

    // Plans are bought with credits; when a paid plan ends it renews automatically if there are enough credits.
    'max_purchase_quantity' => 20,
];
