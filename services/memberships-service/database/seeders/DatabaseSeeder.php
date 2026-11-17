<?php

namespace Database\Seeders;

use App\Models\CreditPack;
use App\Models\Plan;
use App\Models\StudyRoom;
use Illuminate\Database\Seeder;

/**
 * Plans (Basic free + Bronze, Silver, Gold), their limits, the credit packs (same codes as the
 * credit-pack document types of payments-service) and the study rooms.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * feature => [basic, bronze, silver, gold]; each value is [enabled, value].
     * quota: value null = unlimited · time: hours / minutes per cycle.
     */
    private const MATRIX = [
        'paperwork.requests' => [[true, 2], [true, 4], [true, 8], [true, null]],
        'invoicing.invoices' => [[false, 0], [false, 0], [true, 3], [true, null]],
        'cafeteria.orders' => [[true, 3], [true, 10], [true, 30], [true, null]],
        'weather.forecast' => [[false, null], [false, null], [true, null], [true, null]],
        'credits.bonus_pct' => [[true, 0], [true, 0], [true, 5], [true, 15]],
        'tutoring.sessions' => [[false, 0], [true, 2], [true, 4], [true, null]],
        'tutoring.discount_pct' => [[true, 0], [true, 0], [true, 20], [true, 40]],
        'analytics.reports' => [[false, 0], [true, 1], [true, 3], [true, null]],
        'analytics.cost' => [[true, 0], [true, 30], [true, 15], [true, 0]],
        'study_rooms.hours' => [[true, 0], [true, 2], [true, 6], [true, 15]],
        'study_rooms.max_hours' => [[false, 0], [true, 1], [true, 2], [true, 4]],
        'study_rooms.advance_days' => [[true, 0], [true, 2], [true, 7], [true, 14]],
        'library.minutes' => [[true, 30], [true, 120], [true, 600], [true, 1800]],
    ];

    public function run(): void
    {
        $plans = [
            ['basic', 'Básico', 'Gratis para todo alumno. Acceso esencial con límites bajos.', 0, 30, 0, 'slate'],
            ['bronze', 'Bronze', 'Más trámites, cubículos por hora y asesorías.', 120, 30, 1, 'amber'],
            ['silver', 'Silver', 'Facturas, pronóstico del clima, descuentos y más tiempo.', 250, 30, 2, 'sky'],
            ['gold', 'Gold', 'Sin límites en servicios escolares, máximo descuento y tiempo.', 450, 30, 3, 'indigo'],
        ];

        foreach ($plans as $i => [$code, $name, $description, $price, $days, $tier, $color]) {
            $plan = Plan::updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => $description, 'price_credits' => $price,
                'duration_days' => $days, 'tier' => $tier, 'color' => $color, 'is_active' => true,
            ]);
            foreach (self::MATRIX as $feature => $values) {
                [$enabled, $value] = $values[$i];
                $plan->features()->updateOrCreate(['feature' => $feature], ['enabled' => $enabled, 'value' => $value]);
            }
        }

        // Money → credits. code = document type code in payments-service; price must match the price there.
        foreach ([
            ['creditos_100', 'Paquete de 100 créditos', 100, '100.00'],
            ['creditos_550', 'Paquete de 550 créditos', 550, '500.00'],
            ['creditos_1200', 'Paquete de 1200 créditos', 1200, '1000.00'],
        ] as [$code, $name, $credits, $price]) {
            CreditPack::updateOrCreate(['code' => $code], ['name' => $name, 'credits' => $credits, 'price' => $price, 'is_active' => true]);
        }

        foreach ([
            ['Cubículo 1', 'Biblioteca, planta alta', 4, 'Pizarrón, contacto eléctrico'],
            ['Cubículo 2', 'Biblioteca, planta alta', 4, 'Pizarrón, contacto eléctrico'],
            ['Cubículo 3', 'Biblioteca, planta baja', 6, 'Pantalla HDMI, pizarrón'],
            ['Sala de cómputo A', 'Edificio de Software', 10, '10 equipos con internet'],
        ] as [$name, $location, $capacity, $equipment]) {
            StudyRoom::updateOrCreate(['name' => $name], ['location' => $location, 'capacity' => $capacity, 'equipment' => $equipment, 'is_active' => true]);
        }
    }
}
