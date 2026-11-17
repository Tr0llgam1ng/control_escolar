<?php

namespace App\Memberships;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request the plan does not allow, or that the wallet / time balance cannot cover.
 * Rendered as JSON with a machine-readable code so the gateway and the app can react
 * (e.g. show "Mejora tu plan").
 *
 *  PLAN_REQUIRED        403  the feature is not included in the current plan
 *  PLAN_LIMIT           403  included, but the limit of the cycle was reached
 *  INSUFFICIENT_CREDITS 422
 *  INSUFFICIENT_TIME    422
 */
class MembershipException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function planRequired(string $feature, string $message, array $plans): self
    {
        return new self($message, 'PLAN_REQUIRED', 403, ['feature' => $feature, 'upgrade_to' => $plans]);
    }

    public static function planLimit(string $feature, string $message, array $plans): self
    {
        return new self($message, 'PLAN_LIMIT', 403, ['feature' => $feature, 'upgrade_to' => $plans]);
    }

    public static function insufficientCredits(int $needed, int $balance): self
    {
        return new self(
            "Créditos insuficientes: se necesitan {$needed} y tienes {$balance}. Compra un paquete de créditos.",
            'INSUFFICIENT_CREDITS', 422, ['needed' => $needed, 'balance' => $balance],
        );
    }

    public static function insufficientTime(string $module, int $needed, int $available): self
    {
        return new self(
            'Tiempo insuficiente: necesitas '.Duration::human($needed).' y te quedan '.Duration::human($available).'. Compra tiempo extra o mejora tu plan.',
            'INSUFFICIENT_TIME', 422, ['module' => $module, 'needed_seconds' => $needed, 'available_seconds' => $available],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode, ...$this->extra], $this->status);
    }
}
