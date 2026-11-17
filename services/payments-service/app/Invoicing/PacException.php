<?php

namespace App\Invoicing;

use RuntimeException;

/** The PAC refused the request (validation, SAT rule, token) or could not be reached. */
class PacException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $pacCode = null,
        public readonly ?string $satCode = null,
        public readonly ?string $category = null,
        public readonly bool $unreachable = false,
        /** Low-level cause (cURL error) for logs and the invoicing:check command; never shown to students. */
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $httpStatus, ?array $body): self
    {
        $message = trim((string) ($body['MensajeSat'] ?? '')) ?: trim((string) ($body['Mensaje'] ?? ''));

        return new self(
            $message !== '' ? $message : "El PAC respondió HTTP {$httpStatus} sin detalle.",
            isset($body['Codigo']) ? (int) $body['Codigo'] : null,
            isset($body['CodigoSat']) && $body['CodigoSat'] !== '' ? (string) $body['CodigoSat'] : null,
            $body['Categoria'] ?? null,
        );
    }

    /** One line for the user: "[CFDI40999] Fecha.- La fecha..." */
    public function describe(): string
    {
        $code = $this->satCode ?? ($this->pacCode !== null ? (string) $this->pacCode : null);

        return ($code ? "[{$code}] " : '').$this->getMessage();
    }
}
