<?php

namespace App\Invoicing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client of the TimbraCFDI API v2 (ProFact). JSON over HTTPS with a Bearer integrator token.
 * Every call answers 200 on success; 400/401/500 carry {Categoria, Codigo, CodigoSat, Mensaje, MensajeSat, Valores}.
 */
class TimbraCfdiClient
{
    /**
     * Stamps an (unsigned) CFDI. The PAC seals it with the issuer's CSD and adds the TimbreFiscalDigital.
     *
     * @return array{uuid: string, xml: string, qr_png_base64: ?string, original_chain: ?string}
     */
    public function stamp(string $xml, ?string $internalId = null): array
    {
        $body = $this->post('TimbraCFDI', array_filter([
            'XmlComprobanteBase64' => base64_encode($xml),
            'IdComprobante' => $internalId,
        ]));

        return $this->stampedDocument($body);
    }

    /** Recovers an already stamped CFDI (XML, QR and original chain). */
    public function get(string $issuerRfc, string $uuid): array
    {
        return $this->stampedDocument($this->post('ObtieneCFDI', ['FolioFiscal' => $uuid, 'RfcEmisor' => $issuerRfc]));
    }

    /**
     * Cancellation request to the SAT through the PAC.
     *
     * @return array{message: string, sat_code: ?string, ack_xml: ?string}
     */
    public function cancel(string $issuerRfc, string $uuid, string $reason, ?string $replacementUuid = null): array
    {
        $body = $this->post('CancelaCFDI', [
            'RfcEmisor' => $issuerRfc,
            'FolioFiscal' => $uuid,
            'MotivoCancelacion' => $reason,
            'FolioFiscalSustitucion' => $replacementUuid ?? '',
        ]);

        return [
            'message' => (string) ($body['MensajeSat'] ?? $body['ResultadoSat'] ?? 'Solicitud de cancelación enviada.'),
            'sat_code' => isset($body['CodigoSat']) ? (string) $body['CodigoSat'] : null,
            'ack_xml' => ($body['XmlAcuse'] ?? '') !== '' ? (string) $body['XmlAcuse'] : null,
        ];
    }

    /** Status of a CFDI straight from the SAT (Vigente / Cancelado, cancellable or not). */
    public function satStatus(string $uuid): array
    {
        return (array) ($this->post('ConsultaEstatusSat', ['FolioFiscal' => $uuid])['RespuestaSat'] ?? []);
    }

    /** Stamps left for the issuer (or for the integrator account when $issuerRfc is null). */
    public function availableStamps(?string $issuerRfc): array
    {
        return $this->post('ObtieneTimbresDisponibles', ['RfcEmisor' => $issuerRfc]);
    }

    private function post(string $operation, array $payload): array
    {
        $url = rtrim((string) config('invoicing.pac.url'), '/').'/'.config('invoicing.pac.version').'/Timbrado/'.$operation;

        try {
            // XAMPP's PHP often has no CA bundle configured: allow pointing to a cacert.pem just for the PAC.
            $caBundle = config('invoicing.pac.ca_bundle');

            $response = Http::withToken((string) config('invoicing.pac.token'))
                ->withOptions($caBundle ? ['verify' => $caBundle] : [])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('invoicing.pac.timeout', 30))
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('PAC unreachable', ['operation' => $operation, 'error' => $e->getMessage()]);
            throw new PacException('No fue posible conectar con el PAC de facturación. Intenta más tarde.', unreachable: true, detail: $e->getMessage());
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || (isset($body['Codigo']) && (int) $body['Codigo'] !== 0)) {
            Log::warning('PAC error', ['operation' => $operation, 'status' => $response->status(), 'body' => is_array($body) ? $body : $response->body()]);
            throw PacException::fromResponse($response->status(), is_array($body) ? $body : null);
        }

        return $body;
    }

    private function stampedDocument(array $body): array
    {
        $xml = $this->decodeXml((string) ($body['Xml'] ?? ''));
        $uuid = $body['Valores']['UUID'] ?? self::uuidFromXml($xml);
        if (! $uuid) {
            throw new PacException('El PAC respondió sin folio fiscal (UUID).');
        }

        return [
            'uuid' => strtoupper((string) $uuid),
            'xml' => $xml,
            'qr_png_base64' => $this->qrToBase64($body['CodigoQr'] ?? null),
            'original_chain' => isset($body['CadenaOriginalTimbre']) ? (string) $body['CadenaOriginalTimbre'] : null,
        ];
    }

    /** The XML may come as plain text or Base64: accept both. */
    private function decodeXml(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '<')) {
            return $value;
        }
        $decoded = base64_decode($value, true);

        return $decoded !== false && str_starts_with(ltrim($decoded, "\xEF\xBB\xBF \n\r\t"), '<') ? $decoded : $value;
    }

    /** CodigoQr is a .NET byte[]: JSON gives it as a Base64 string (or, in some clients, as an array of ints). */
    private function qrToBase64(mixed $qr): ?string
    {
        if (is_array($qr) && $qr !== []) {
            return base64_encode(pack('C*', ...array_map('intval', $qr)));
        }

        return is_string($qr) && $qr !== '' && base64_decode($qr, true) !== false ? $qr : null;
    }

    public static function uuidFromXml(string $xml): ?string
    {
        return preg_match('/TimbreFiscalDigital[^>]*\sUUID="([0-9A-Fa-f-]{36})"/', $xml, $m) ? $m[1] : null;
    }
}
