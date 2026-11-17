<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CFDI 4.0 of a paid reference.
 *  stamped   → valid invoice, has UUID and XML
 *  cancelled → cancellation requested to the SAT through the PAC
 *  failed    → the PAC rejected it (kept for audit, with the SAT error)
 */
class Invoice extends Model
{
    public const STAMPED = 'stamped';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const STAMPING = 'stamping'; // transient: the PAC call is in progress

    public const STATUS_LABELS = ['stamped' => 'Timbrada', 'cancelled' => 'Cancelada', 'failed' => 'Error al timbrar', 'stamping' => 'Timbrando'];

    protected $guarded = ['id'];

    /** The XML, QR and acknowledgment are big: they are served by their own endpoints. */
    protected $hidden = ['xml', 'qr_png', 'cancel_ack'];

    protected $appends = ['status_label'];

    protected function casts(): array
    {
        return [
            'charge_id' => 'integer',
            'student_id' => 'integer',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'stamped_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Data needed for the printed representation (includes the QR and the original chain). */
    public function printable(): array
    {
        return $this->makeVisible('qr_png')->toArray() + [
            'issuer' => [
                'rfc' => $this->issuer_rfc,
                'name' => config('invoicing.issuer.name'),
                'regime' => config('invoicing.issuer.regime'),
                'zip' => config('invoicing.issuer.zip'),
            ],
            'seals' => self::sealsFromXml((string) $this->xml),
        ];
    }

    /** Digital seals and certificate numbers, read from the stamped XML. */
    public static function sealsFromXml(string $xml): array
    {
        $attr = function (string $node, string $name) use ($xml): ?string {
            return preg_match('/<[\w:]*'.$node.'\b[^>]*\s'.$name.'="([^"]*)"/', $xml, $m) ? $m[1] : null;
        };

        return [
            'cfdi_seal' => $attr('Comprobante', 'Sello'),
            'issuer_certificate' => $attr('Comprobante', 'NoCertificado'),
            'sat_seal' => $attr('TimbreFiscalDigital', 'SelloSAT'),
            'sat_certificate' => $attr('TimbreFiscalDigital', 'NoCertificadoSAT'),
            'certified_at' => $attr('TimbreFiscalDigital', 'FechaTimbrado'),
            'pac_rfc' => $attr('TimbreFiscalDigital', 'RfcProvCertif'),
        ];
    }
}
