<?php

return [
    'currency' => env('PAYMENTS_CURRENCY', 'MXN'),

    // Dates shown to people (reference expiration) are formatted in this timezone.
    'timezone' => env('PAYMENTS_TIMEZONE', 'America/Mexico_City'),

    // How long a payment reference stays alive. After that, if unpaid, Control Escolar rejects it.
    'reference_ttl_hours' => (int) env('PAYMENTS_REFERENCE_TTL_HOURS', 72),

    'reference_max_attempts' => 5,
];
