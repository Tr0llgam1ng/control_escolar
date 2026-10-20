<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Payments\BankSimulator;
use App\Payments\Money;
use App\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contract: Payments Service v2 — payments
 *  POST /api/charges/{id}/pay    owner student: {method: card|cash|transfer, card?: {...}}
 *                                → {result: approved|rejected|pending, message, charge, transaction?}
 *  POST /api/charges/{id}/cash   admin: {amount_received} cash received at the window
 *
 * The amount is never accepted from the client: it is always the amount of the reference.
 * Card data is checked against the local bank simulator and only the last 4 digits are stored.
 */
class PaymentController extends Controller
{
    public function pay(Request $request, PaymentService $payments, int $id): JsonResponse
    {
        $charge = Charge::find($id);
        abort_if(! $charge, 404, 'La referencia de pago no existe.');
        abort_unless($this->ownStudentId($request) === $charge->student_id, 403, 'Solo puedes pagar tus propias referencias.');

        $request->merge(['card' => array_merge((array) $request->input('card', []), [
            'number' => preg_replace('/\D/', '', (string) $request->input('card.number', '')),
        ])]);

        $data = $request->validate([
            'method' => ['required', Rule::in([Charge::CARD, Charge::CASH, Charge::TRANSFER])],
            'amount' => ['prohibited'],
            'card' => ['exclude_unless:method,card', 'required', 'array'],
            'card.number' => ['exclude_unless:method,card', 'required', 'digits_between:13,19', function ($attr, $value, $fail) {
                if (! BankSimulator::luhnValid((string) $value)) {
                    $fail('El número de tarjeta no es válido.');
                }
            }],
            'card.holder_name' => ['exclude_unless:method,card', 'required', 'string', 'max:100'],
            'card.exp_month' => ['exclude_unless:method,card', 'required', 'integer', 'between:1,12'],
            'card.exp_year' => ['exclude_unless:method,card', 'required', 'integer', 'between:2000,2099'],
            'card.cvv' => ['exclude_unless:method,card', 'required', 'digits_between:3,4'],
        ], [
            'method.in' => 'Método de pago inválido.',
            'amount.prohibited' => 'El monto no se puede enviar: se toma de la referencia.',
            'card.number.required' => 'Captura el número de tarjeta.',
            'card.number.digits_between' => 'El número de tarjeta debe tener entre 13 y 19 dígitos.',
            'card.holder_name.required' => 'Captura el nombre del titular.',
            'card.exp_month.*' => 'Mes de vencimiento inválido.',
            'card.exp_year.*' => 'Año de vencimiento inválido.',
            'card.cvv.*' => 'El CVV debe tener 3 o 4 dígitos.',
        ]);

        if ($data['method'] === Charge::TRANSFER) {
            abort(422, 'El pago por transferencia no está disponible por el momento. Elige tarjeta o efectivo.');
        }

        if ($data['method'] === Charge::CASH) {
            $charge = $payments->chooseCash($id, $this->auditActor($request));

            return response()->json([
                'result' => Charge::PENDING,
                'message' => "Pago pendiente. Acude a la caja de Control Escolar con tu referencia {$charge->reference} y paga \${$charge->amount} antes del "
                    .$charge->expires_at->copy()->setTimezone(config('payments.timezone'))->format('d/m/Y H:i').'.',
                'charge' => $charge->load('documentType'),
                'transaction' => null,
            ]);
        }

        $outcome = $payments->payWithCard($id, $data['card'], $this->auditActor($request));
        $outcome['charge']->load('documentType');

        return response()->json($outcome);
    }

    public function receiveCash(Request $request, PaymentService $payments, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'amount_received' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
        ], [
            'amount_received.required' => 'Captura la cantidad recibida.',
            'amount_received.regex' => 'Cantidad inválida (ej. 150 o 150.50).',
        ]);

        $result = $payments->receiveCash($id, (string) $data['amount_received'], $this->auditActor($request));
        $result['charge']->load(['documentType', 'transactions', 'auditLogs']);
        $result['message'] = 'Pago en efectivo registrado. Cambio: $'.Money::fromCents(Money::toCents((string) $result['transaction']->change_given)).'.';

        return response()->json($result);
    }
}
