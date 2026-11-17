<?php

namespace Tests\Unit;

use App\Invoicing\CfdiBuilder;
use App\Invoicing\SatCatalogs;
use App\Invoicing\TimbraCfdiClient;
use Tests\TestCase;

class CfdiBuilderTest extends TestCase
{
    public function test_vat_breakdown_always_adds_up_to_the_paid_total(): void
    {
        foreach (range(1, 300000, 7) as $total) {
            ['base' => $base, 'tax' => $tax] = CfdiBuilder::splitTax($total, '0.160000');
            $this->assertSame($total, $base + $tax, "total {$total}");
            // SAT accepts the tax within one cent of base * rate.
            $this->assertLessThanOrEqual(1, abs($tax - $base * 0.16), "total {$total}");
        }

        $this->assertSame(['base' => 12931, 'tax' => 2069], CfdiBuilder::splitTax(15000, '0.160000'));
        $this->assertSame(['base' => 155172, 'tax' => 24828], CfdiBuilder::splitTax(180000, '0.160000'));
    }

    public function test_exempt_mode_has_no_rate_or_tax_amount(): void
    {
        config(['invoicing.tax_mode' => 'exento']);

        $built = app(CfdiBuilder::class)->build([
            'series' => 'ESC', 'folio' => '1', 'date' => '2026-11-02T10:00:00', 'payment_form' => '01',
            'total_cents' => 15000, 'description' => 'Constancia', 'item_id' => 'constancia',
        ], ['rfc' => 'EKU9003173C9', 'name' => 'ESCUELA KEMPER URGATE', 'zip' => '42501', 'regime' => '603', 'cfdi_use' => 'G03']);

        $this->assertSame(['150.00', '0.00', '150.00'], [$built['subtotal'], $built['tax'], $built['total']]);
        $this->assertStringContainsString('TipoFactor="Exento"', $built['xml']);
        $this->assertStringNotContainsString('TasaOCuota', $built['xml']);
        $this->assertStringNotContainsString('TotalImpuestosTrasladados', $built['xml']);
    }

    public function test_rfc_format_and_uuid_parsing(): void
    {
        $this->assertTrue(SatCatalogs::validRfc('EKU9003173C9'));
        $this->assertTrue(SatCatalogs::validRfc('XAXX010101000'));
        $this->assertTrue(SatCatalogs::validRfc('XOJI740919U48'));
        $this->assertFalse(SatCatalogs::validRfc('EKU9013173C9')); // month 13
        $this->assertFalse(SatCatalogs::validRfc('EKU900317'));

        $this->assertSame(
            '738f8bb0-18f4-4944-b870-02c3e790a197',
            TimbraCfdiClient::uuidFromXml('<tfd:TimbreFiscalDigital Version="1.1" UUID="738f8bb0-18f4-4944-b870-02c3e790a197" />')
        );
    }
}
