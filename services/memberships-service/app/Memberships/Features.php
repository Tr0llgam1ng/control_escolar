<?php

namespace App\Memberships;

/**
 * Registry of what a plan can grant. Each plan stores one row per feature (plan_features):
 *
 *  quota   → uses per cycle. value null = unlimited, 0 or disabled = not included
 *  flag    → on / off
 *  time    → time included per cycle (value in hours or minutes, see unit)
 *  percent → a percentage (bonus, discount)
 *  credits → a price in credits
 *  number  → a plain limit (max hours per booking, days in advance)
 */
final class Features
{
    public const DEFINITIONS = [
        // School services (existing modules, filtered by plan in the gateway)
        'paperwork.requests' => ['type' => 'quota', 'group' => 'Servicios escolares', 'label' => 'Referencias de trámites (constancias, kárdex…)'],
        'invoicing.invoices' => ['type' => 'quota', 'group' => 'Servicios escolares', 'label' => 'Facturas electrónicas CFDI'],
        'cafeteria.orders' => ['type' => 'quota', 'group' => 'Servicios escolares', 'label' => 'Pedidos en la cafetería'],
        'weather.forecast' => ['type' => 'flag', 'group' => 'Servicios escolares', 'label' => 'Pronóstico del clima por horas y a 5 días'],
        // Credits
        'credits.bonus_pct' => ['type' => 'percent', 'group' => 'Créditos', 'label' => 'Créditos de regalo al comprar un paquete'],
        // Credits module 1: tutoring
        'tutoring.sessions' => ['type' => 'quota', 'group' => 'Asesorías con profesor (créditos)', 'label' => 'Asesorías por ciclo'],
        'tutoring.discount_pct' => ['type' => 'percent', 'group' => 'Asesorías con profesor (créditos)', 'label' => 'Descuento en asesorías'],
        // Credits module 2: academic analysis
        'analytics.reports' => ['type' => 'quota', 'group' => 'Análisis académico (créditos)', 'label' => 'Análisis académicos por ciclo'],
        'analytics.cost' => ['type' => 'credits', 'group' => 'Análisis académico (créditos)', 'label' => 'Costo por análisis'],
        // Time module 1: study rooms (hours)
        'study_rooms.hours' => ['type' => 'time', 'unit' => 'hours', 'group' => 'Cubículos de estudio (horas)', 'label' => 'Horas incluidas por ciclo'],
        'study_rooms.max_hours' => ['type' => 'number', 'unit' => 'h', 'group' => 'Cubículos de estudio (horas)', 'label' => 'Horas máximas por reservación'],
        'study_rooms.advance_days' => ['type' => 'number', 'unit' => 'días', 'group' => 'Cubículos de estudio (horas)', 'label' => 'Anticipación máxima para reservar'],
        // Time module 2: digital library (minutes)
        'library.minutes' => ['type' => 'time', 'unit' => 'minutes', 'group' => 'Biblioteca digital (minutos)', 'label' => 'Minutos incluidos por ciclo'],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    public static function type(string $key): string
    {
        return self::DEFINITIONS[$key]['type'];
    }

    /** Seconds of one unit of a time feature. */
    public static function unitSeconds(string $key): int
    {
        return (self::DEFINITIONS[$key]['unit'] ?? null) === 'hours' ? 3600 : 60;
    }

    /** Is the feature usable at all with this configuration? */
    public static function available(string $key, bool $enabled, ?int $value): bool
    {
        if (! $enabled) {
            return false;
        }

        return match (self::type($key)) {
            'quota' => $value === null || $value > 0,
            'number' => ($value ?? 0) > 0,
            default => true,
        };
    }

    /** Labelled description of one feature value, as shown in the comparison table. */
    public static function describe(string $key, bool $enabled, ?int $value): array
    {
        $def = self::DEFINITIONS[$key];
        $available = self::available($key, $enabled, $value);

        $display = match (true) {
            ! $available && $def['type'] !== 'time' => 'No incluido',
            $def['type'] === 'quota' => $value === null ? 'Ilimitado' : "{$value} por ciclo",
            $def['type'] === 'flag' => 'Incluido',
            $def['type'] === 'percent' => ($value ?? 0) > 0 ? "{$value}%" : (str_contains($key, 'discount') ? 'Sin descuento' : 'Sin regalo'),
            $def['type'] === 'credits' => ($value ?? 0) > 0 ? "{$value} créditos" : 'Gratis',
            $def['type'] === 'time' => ! $enabled || ! $value ? 'No incluido' : $value.($def['unit'] === 'hours' ? ' h' : ' min').' por ciclo',
            default => $value.' '.($def['unit'] ?? ''),
        };

        return [
            'key' => $key,
            'label' => $def['label'],
            'group' => $def['group'],
            'type' => $def['type'],
            'unit' => $def['unit'] ?? null,
            'enabled' => $enabled,
            'value' => $value,
            'available' => $available,
            'unlimited' => $def['type'] === 'quota' && $available && $value === null,
            'display' => trim($display),
        ];
    }
}
