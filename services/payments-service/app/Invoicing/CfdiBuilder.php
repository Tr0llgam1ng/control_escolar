<?php

namespace App\Invoicing;

use App\Payments\Money;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Builds the unsigned CFDI 4.0 XML (Anexo 20) of one paid fee. No Sello / NoCertificado / Certificado:
 * the PAC seals it ("autosellado").
 *
 * Amounts work in integer cents. The fee price is the final price the student paid, so with VAT the
 * base is broken out of it and adjusted until base + VAT equals the paid amount to the cent.
 */
class CfdiBuilder
{
    private const NS_CFDI = 'http://www.sat.gob.mx/cfd/4';

    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const SCHEMA = 'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd';

    /**
     * Splits a total (VAT included) into base and VAT so that base + round(base * rate) == total.
     *
     * @return array{base: int, tax: int} cents
     */
    public static function splitTax(int $totalCents, string $rate): array
    {
        $rateMicros = (int) round(((float) $rate) * 1_000_000); // 0.160000 → 160000
        if ($rateMicros <= 0) {
            return ['base' => $totalCents, 'tax' => 0];
        }

        $tax = fn (int $base): int => intdiv($base * $rateMicros + 500_000, 1_000_000); // round half up
        $guess = (int) round($totalCents * 1_000_000 / (1_000_000 + $rateMicros));

        foreach ([0, -1, 1, -2, 2] as $delta) {
            $base = $guess + $delta;
            if ($base + $tax($base) === $totalCents) {
                return ['base' => $base, 'tax' => $tax($base)];
            }
        }

        // Within the SAT tolerance anyway (one cent); keep the paid total exact.
        return ['base' => $guess, 'tax' => $totalCents - $guess];
    }

    /**
     * @param  array{series: string, folio: string, date: string, payment_form: string, total_cents: int, description: string, item_id: string}  $doc
     * @param  array{rfc: string, name: string, zip: string, regime: string, cfdi_use: string}  $receiver
     * @return array{xml: string, subtotal: string, tax: string, total: string}
     */
    public function build(array $doc, array $receiver): array
    {
        $issuer = config('invoicing.issuer');
        $mode = config('invoicing.tax_mode', 'iva');
        if (! in_array($mode, ['iva', 'exento'], true)) {
            throw new InvalidArgumentException("INVOICING_TAX_MODE must be iva or exento, got {$mode}.");
        }
        $rate = number_format((float) config('invoicing.iva_rate', '0.16'), 6, '.', '');

        ['base' => $base, 'tax' => $tax] = $mode === 'iva'
            ? self::splitTax($doc['total_cents'], $rate)
            : ['base' => $doc['total_cents'], 'tax' => 0];

        $m = fn (int $cents) => Money::fromCents($cents);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS_CFDI, 'cfdi:Comprobante');
        $dom->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::NS_XSI);
        $root->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', self::SCHEMA);
        $this->attrs($root, [
            'Version' => '4.0',
            'Serie' => $doc['series'],
            'Folio' => $doc['folio'],
            'Fecha' => $doc['date'],
            'FormaPago' => $doc['payment_form'],
            'SubTotal' => $m($base),
            'Moneda' => 'MXN',
            'Total' => $m($base + $tax),
            'TipoDeComprobante' => 'I',
            'Exportacion' => '01',
            'MetodoPago' => 'PUE',
            'LugarExpedicion' => $issuer['zip'],
        ]);

        $this->child($root, 'cfdi:Emisor', ['Rfc' => $issuer['rfc'], 'Nombre' => $issuer['name'], 'RegimenFiscal' => $issuer['regime']]);
        $this->child($root, 'cfdi:Receptor', [
            'Rfc' => $receiver['rfc'],
            'Nombre' => $receiver['name'],
            // With the generic RFC, SAT requires the issuer's postal code here.
            'DomicilioFiscalReceptor' => $receiver['rfc'] === SatCatalogs::GENERIC_RFC ? $issuer['zip'] : $receiver['zip'],
            'RegimenFiscalReceptor' => $receiver['regime'],
            'UsoCFDI' => $receiver['cfdi_use'],
        ]);

        $concepts = $this->child($root, 'cfdi:Conceptos');
        $concept = $this->child($concepts, 'cfdi:Concepto', [
            'ClaveProdServ' => config('invoicing.product_code'),
            'NoIdentificacion' => $doc['item_id'],
            'Cantidad' => '1',
            'ClaveUnidad' => config('invoicing.unit_code'),
            'Unidad' => config('invoicing.unit_name'),
            'Descripcion' => $doc['description'],
            'ValorUnitario' => $m($base),
            'Importe' => $m($base),
            'ObjetoImp' => '02',
        ]);

        $transfer = $mode === 'iva'
            ? ['Base' => $m($base), 'Impuesto' => '002', 'TipoFactor' => 'Tasa', 'TasaOCuota' => $rate, 'Importe' => $m($tax)]
            : ['Base' => $m($base), 'Impuesto' => '002', 'TipoFactor' => 'Exento'];

        $lineTaxes = $this->child($concept, 'cfdi:Impuestos');
        $this->child($this->child($lineTaxes, 'cfdi:Traslados'), 'cfdi:Traslado', $transfer);

        // Exempt-only documents carry no TotalImpuestosTrasladados.
        $totals = $this->child($root, 'cfdi:Impuestos', $mode === 'iva' ? ['TotalImpuestosTrasladados' => $m($tax)] : []);
        $this->child($this->child($totals, 'cfdi:Traslados'), 'cfdi:Traslado', $transfer);

        return [
            'xml' => $dom->saveXML(),
            'subtotal' => $m($base),
            'tax' => $m($tax),
            'total' => $m($base + $tax),
        ];
    }

    private function child(DOMElement $parent, string $name, array $attributes = []): DOMElement
    {
        $element = $parent->ownerDocument->createElementNS(self::NS_CFDI, $name);
        $this->attrs($element, $attributes);
        $parent->appendChild($element);

        return $element;
    }

    private function attrs(DOMElement $element, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            if ($value !== null && $value !== '') {
                $element->setAttribute($name, (string) $value);
            }
        }
    }
}
