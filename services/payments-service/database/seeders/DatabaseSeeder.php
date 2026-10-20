<?php

namespace Database\Seeders;

use App\Models\Charge;
use App\Models\DocumentType;
use App\Models\TestCard;
use App\Payments\ChargeService;
use Illuminate\Database\Seeder;

/**
 * Document catalog, test cards of the local bank simulator and a few demo references.
 * student_id 1 is the catalog record of the demo student (alumno@escolar.test).
 */
class DatabaseSeeder extends Seeder
{
    public function run(ChargeService $charges): void
    {
        $types = [
            ['constancia_estudios', 'Constancia de estudios', 'Acredita que eres alumno inscrito en el periodo actual.', '150.00'],
            ['boleta', 'Boleta de calificaciones', 'Calificaciones del periodo actual.', '80.00'],
            ['kardex', 'Kárdex', 'Historial académico completo con promedio general.', '120.00'],
            ['comprobante_inscripcion', 'Comprobante de inscripción', 'Materias en las que estás inscrito en el periodo actual.', '100.00'],
            ['inscripcion', 'Pago de inscripción / reinscripción', 'Cuota de inscripción al periodo.', '1800.00'],
        ];
        foreach ($types as [$code, $name, $description, $price]) {
            DocumentType::updateOrCreate(['code' => $code], ['name' => $name, 'description' => $description, 'price' => $price, 'is_active' => true]);
        }

        // Test cards (any holder name is accepted). These are well-known test numbers, not real cards.
        $cards = [
            ['4111111111111111', 'VISA', 12, 2030, '123', '20000.00', TestCard::ACTIVE, 'Aprobada'],
            ['5555555555554444', 'MASTERCARD', 11, 2029, '321', '5000.00', TestCard::ACTIVE, 'Aprobada'],
            ['4000000000000002', 'VISA', 12, 2030, '123', '50.00', TestCard::ACTIVE, 'Fondos insuficientes'],
            ['4000000000000069', 'VISA', 1, 2024, '123', '20000.00', TestCard::ACTIVE, 'Vencida'],
            ['4000000000000119', 'VISA', 12, 2030, '123', '20000.00', TestCard::BLOCKED, 'Bloqueada'],
        ];
        foreach ($cards as [$number, $brand, $month, $year, $cvv, $balance, $status, $notes]) {
            TestCard::updateOrCreate(['number' => $number], [
                'holder_name' => 'TARJETA DE PRUEBA', 'brand' => $brand, 'exp_month' => $month, 'exp_year' => $year,
                'cvv_hash' => password_hash($cvv, PASSWORD_BCRYPT), 'balance' => $balance, 'status' => $status, 'notes' => $notes,
            ]);
        }

        if (Charge::exists()) {
            return;
        }

        // Demo references for the demo student: one alive and one already expired (to be rejected).
        $actor = ['type' => 'service', 'id' => 'seeder'];
        $charges->create(1, DocumentType::where('code', 'constancia_estudios')->first(), $actor);
        [$expired] = $charges->create(1, DocumentType::where('code', 'boleta')->first(), $actor);
        $expired->forceFill(['created_at' => now()->subDays(5), 'expires_at' => now()->subDays(2)])->save();
    }
}
