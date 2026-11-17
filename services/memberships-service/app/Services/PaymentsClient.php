<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service-to-service call to the Payments Service (internal endpoint, no user):
 * "is this credit-pack reference paid, for which student, which pack and how much?"
 * The Memberships Service never reads the payments database.
 */
class PaymentsClient
{
    /** @return array{id:int, reference:string, student_id:int, status:string, paid:bool, is_expired:bool, amount:string, document_type_code:?string, category:?string} */
    public function chargeStatus(int $chargeId): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.payments.url'), '/'))
                ->acceptJson()
                ->timeout(5)
                ->withHeaders([
                    'X-Service-Key' => (string) config('services.internal.key'),
                    'X-Service-Name' => (string) config('services.self_name'),
                ])
                ->get("/api/charges/{$chargeId}/status");
        } catch (ConnectionException) {
            throw new HttpException(503, 'El servicio de pagos no está disponible.');
        }

        if ($response->status() === 404) {
            throw new HttpException(404, 'La referencia de pago no existe.');
        }
        if ($response->failed()) {
            throw new HttpException(502, 'Error al consultar el servicio de pagos ('.$response->status().').');
        }

        return $response->json();
    }
}
