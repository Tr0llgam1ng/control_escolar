<?php

namespace App\Invoicing;

/**
 * Subset of the SAT catalogs (Anexo 20, CFDI 4.0) a school needs to invoice its fees.
 * F = persona física, M = persona moral.
 */
final class SatCatalogs
{
    /** Generic RFC for people without RFC ("público en general"). */
    public const GENERIC_RFC = 'XAXX010101000';

    /** c_RegimenFiscal => [description, applies to F, applies to M] */
    public const REGIMES = [
        '601' => ['General de Ley Personas Morales', false, true],
        '603' => ['Personas Morales con Fines no Lucrativos', false, true],
        '605' => ['Sueldos y Salarios e Ingresos Asimilados a Salarios', true, false],
        '606' => ['Arrendamiento', true, false],
        '607' => ['Régimen de Enajenación o Adquisición de Bienes', true, false],
        '608' => ['Demás ingresos', true, false],
        '611' => ['Ingresos por Dividendos (socios y accionistas)', true, false],
        '612' => ['Personas Físicas con Actividades Empresariales y Profesionales', true, false],
        '614' => ['Ingresos por intereses', true, false],
        '615' => ['Régimen de los ingresos por obtención de premios', true, false],
        '616' => ['Sin obligaciones fiscales', true, false],
        '621' => ['Incorporación Fiscal', true, false],
        '625' => ['Actividades Empresariales con ingresos a través de Plataformas Tecnológicas', true, false],
        '626' => ['Régimen Simplificado de Confianza', true, true],
    ];

    /** c_UsoCFDI => [description, allowed receiver regimes] */
    public const USES = [
        'G03' => ['Gastos en general', ['601', '603', '606', '612', '621', '625', '626']],
        'D10' => ['Pagos por servicios educativos (colegiaturas)', ['605', '606', '607', '608', '611', '612', '614', '615', '625']],
        'S01' => ['Sin efectos fiscales', ['601', '603', '605', '606', '607', '608', '611', '612', '614', '615', '616', '621', '625', '626']],
    ];

    /** c_MotivoCancelacion */
    public const CANCEL_REASONS = [
        '01' => 'Comprobante emitido con errores con relación',
        '02' => 'Comprobante emitido con errores sin relación',
        '03' => 'No se llevó a cabo la operación',
        '04' => 'Operación nominativa relacionada en una factura global',
    ];

    public static function isPersonaMoral(string $rfc): bool
    {
        return strlen($rfc) === 12;
    }

    public static function validRfc(string $rfc): bool
    {
        return preg_match('/^([A-ZÑ&]{3,4})(\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])([A-Z\d]{2}[A\d])$/u', $rfc) === 1;
    }

    /** @return array{regimes: array, uses: array, cancel_reasons: array} for the front end */
    public static function toArray(): array
    {
        return [
            'regimes' => collect(self::REGIMES)->map(fn ($r, $code) => ['code' => (string) $code, 'name' => $r[0], 'fisica' => $r[1], 'moral' => $r[2]])->values(),
            'uses' => collect(self::USES)->map(fn ($u, $code) => ['code' => $code, 'name' => $u[0], 'regimes' => $u[1]])->values(),
            'cancel_reasons' => collect(self::CANCEL_REASONS)->map(fn ($name, $code) => ['code' => (string) $code, 'name' => $name])->values(),
            'generic_rfc' => self::GENERIC_RFC,
        ];
    }
}
