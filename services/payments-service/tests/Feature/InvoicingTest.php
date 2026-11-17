<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Invoicing through the PAC. The PAC (TimbraCFDI) and catalog-service are faked with Http::fake. */
class InvoicingTest extends PaymentsTestCase
{
    private const UUID = '738F8BB0-18F4-4944-B870-02C3E790A197';

    private const RECEIVER = ['rfc' => 'EKU9003173C9', 'name' => 'Escuela Kemper Urgate', 'zip' => '42501', 'regime' => '603', 'cfdi_use' => 'G03'];

    /** What the PAC answers next: 'ok', 'error' or 'down'. */
    private string $pacMode = 'ok';

    /** @var list<array{op: string, body: array}> */
    private array $pacCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['invoicing.pac.url' => 'https://pac.test/api', 'invoicing.pac.token' => 'test-token', 'invoicing.tax_mode' => 'iva']);

        // Replace the catalog-only fake of the parent with one that also answers the PAC.
        Http::swap(new Factory);
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_starts_with($path, '/api/v2/Timbrado/')) {
                return $this->pac(substr($path, strlen('/api/v2/Timbrado/')), $request);
            }

            return match ($path) {
                '/api/students/by-user/30' => Http::response(['id' => 1]),
                '/api/students/by-user/31' => Http::response(['id' => 2]),
                default => Http::response(['message' => 'Not found'], 404),
            };
        });
    }

    private function pac(string $operation, Request $request)
    {
        $this->pacCalls[] = ['op' => $operation, 'body' => $request->data(), 'auth' => $request->header('Authorization')[0] ?? null];

        if ($this->pacMode === 'down') {
            throw new ConnectionException('Connection refused');
        }
        if ($this->pacMode === 'error') {
            return Http::response([
                'Categoria' => 'Anexo20', 'Codigo' => 409005, 'CodigoSat' => 'CFDI40145',
                'Mensaje' => 'Nombre del receptor no corresponde.', 'MensajeSat' => 'El campo Nombre del receptor, debe pertenecer al nombre asociado al RFC registrado en el campo Rfc del Receptor.',
                'Valores' => [],
            ], 500);
        }

        return match ($operation) {
            'TimbraCFDI' => $this->stampResponse($request),
            'CancelaCFDI' => Http::response([
                'Categoria' => '', 'CodigoSat' => '0', 'MensajeSat' => 'El comprobante será cancelado', 'XmlAcuse' => '', 'Mensaje' => '',
                'Valores' => ['UUID' => self::UUID, 'Instancia' => '0.QA'],
            ]),
            'ConsultaEstatusSat' => Http::response(['Codigo' => 0, 'Mensaje' => '', 'RespuestaSat' => ['EstadoSat' => 'Vigente', 'EsCancelable' => true, 'UUID' => self::UUID]]),
            default => Http::response(['Mensaje' => 'unknown'], 404),
        };
    }

    private function stampResponse(Request $request)
    {
        // First stamp gets the documented UUID, later ones a new one (UUIDs are unique at the SAT).
        $uuid = collect($this->pacCalls)->where('op', 'TimbraCFDI')->count() === 1 ? self::UUID : strtoupper((string) Str::uuid());

        return Http::response([
            'Codigo' => 0, 'Mensaje' => '',
            // As the real PAC: the XML in Base64, the QR as a .NET byte[] (Base64 in JSON).
            'Xml' => base64_encode($this->stampedXml($uuid, base64_decode($request->data()['XmlComprobanteBase64']))),
            'CodigoQr' => base64_encode("\x89PNG fake"),
            'CadenaOriginalTimbre' => '||1.1|'.$uuid.'|2026-11-02T10:00:00|SPR190613I52|...',
            'Valores' => ['UUID' => strtolower($uuid), 'adenda' => ''],
        ]);
    }

    /** The XML we sent, sealed and with a TimbreFiscalDigital, as the PAC returns it. */
    private function stampedXml(string $uuid, string $xml): string
    {
        $xml = preg_replace('/<cfdi:Comprobante /', '<cfdi:Comprobante Sello="SELLOCFDI" NoCertificado="30001000000500003416" ', $xml, 1);

        return str_replace('</cfdi:Comprobante>', '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.$uuid.'" FechaTimbrado="2026-11-02T10:00:00" RfcProvCertif="SPR190613I52" SelloCFD="SELLOCFDI" NoCertificadoSAT="30001000000500003456" SelloSAT="SELLOSAT"/></cfdi:Complemento></cfdi:Comprobante>', $xml);
    }

    private function paidReference(string $code = 'constancia_estudios'): Charge
    {
        $charge = $this->newReference($code);
        $this->payWithCard($charge)->assertOk()->assertJsonPath('result', 'approved');

        return $charge->fresh();
    }

    private function invoice(Charge $charge, array $receiver = [], int $userId = 30, string $role = 'student')
    {
        return $this->asGateway($userId, $role)->postJson("/api/charges/{$charge->id}/invoice", array_merge(self::RECEIVER, $receiver));
    }

    private function sentXml(): string
    {
        $stamp = collect($this->pacCalls)->where('op', 'TimbraCFDI')->last();

        return base64_decode($stamp['body']['XmlComprobanteBase64']);
    }

    public function test_catalogs_are_public_to_users(): void
    {
        $this->asGateway(30, 'student')->getJson('/api/invoicing/catalogs')
            ->assertOk()
            ->assertJsonPath('generic_rfc', 'XAXX010101000')
            ->assertJsonFragment(['code' => 'G03'])
            ->assertJsonFragment(['code' => '616']);
    }

    public function test_student_invoices_a_paid_reference(): void
    {
        $charge = $this->paidReference(); // $150.00

        $response = $this->invoice($charge)
            ->assertCreated()
            ->assertJsonPath('invoice.status', 'stamped')
            ->assertJsonPath('invoice.uuid', self::UUID)
            ->assertJsonPath('invoice.receiver_rfc', 'EKU9003173C9')
            ->assertJsonPath('invoice.receiver_name', 'ESCUELA KEMPER URGATE')
            ->assertJsonPath('invoice.payment_form', '04')
            ->assertJsonPath('invoice.subtotal', '129.31')
            ->assertJsonPath('invoice.tax', '20.69')
            ->assertJsonPath('invoice.total', '150.00')
            ->assertJsonMissingPath('invoice.xml');

        // What went to the PAC: Bearer token and a CFDI 4.0 without seal (the PAC seals it).
        $this->assertSame('Bearer test-token', $this->pacCalls[0]['auth']);
        $xml = $this->sentXml();
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $this->assertSame('4.0', (string) $doc['Version']);
        $this->assertSame('150.00', (string) $doc['Total']);
        $this->assertSame('129.31', (string) $doc['SubTotal']);
        $this->assertSame('PUE', (string) $doc['MetodoPago']);
        $this->assertStringNotContainsString('Sello=', $xml);
        $this->assertStringContainsString('Rfc="EKU9003173C9"', $xml);
        $this->assertStringContainsString('TotalImpuestosTrasladados="20.69"', $xml);
        $this->assertStringContainsString('TasaOCuota="0.160000" Importe="20.69"', $xml);
        $this->assertStringContainsString('Referencia '.$charge->reference, $xml);
        $this->assertMatchesRegularExpression('/Fecha="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}"/', $xml);

        // Printed representation and XML download.
        $id = $response->json('invoice.id');
        $this->asGateway(30, 'student')->getJson("/api/invoices/{$id}")
            ->assertOk()
            ->assertJsonPath('seals.sat_seal', 'SELLOSAT')
            ->assertJsonPath('seals.issuer_certificate', '30001000000500003416')
            ->assertJsonPath('qr_png', base64_encode("\x89PNG fake"))
            ->assertJsonPath('charge.reference', $charge->reference);
        $download = $this->asGateway(30, 'student')->get("/api/invoices/{$id}/xml")->assertOk();
        $this->assertStringContainsString('TimbreFiscalDigital', $download->getContent());
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        // Audit trail on the reference.
        $this->assertTrue($charge->auditLogs()->where('action', 'invoice_stamped')->exists());

        // Fiscal data prefilled next time.
        $this->asGateway(30, 'student')->getJson("/api/charges/{$charge->id}/invoice")
            ->assertOk()
            ->assertJsonPath('can_invoice', false)
            ->assertJsonPath('last_receiver.rfc', 'EKU9003173C9')
            ->assertJsonCount(1, 'invoices');
    }

    public function test_cash_payment_uses_payment_form_01(): void
    {
        $charge = $this->newReference('kardex');
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'cash'])->assertOk();
        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '200'])->assertOk();

        $this->invoice($charge, [], 1, 'admin')->assertCreated()->assertJsonPath('invoice.payment_form', '01');
        $this->assertStringContainsString('FormaPago="01"', $this->sentXml());
    }

    public function test_only_paid_references_and_only_once(): void
    {
        $pending = $this->newReference();
        $this->invoice($pending)->assertStatus(409);
        $this->assertSame([], $this->pacCalls);

        $paid = $this->paidReference('kardex');
        $this->invoice($paid)->assertCreated();
        $this->invoice($paid)->assertStatus(409);
        $this->assertCount(1, $this->pacCalls);
    }

    public function test_ownership_and_roles(): void
    {
        $charge = $this->paidReference();

        $this->invoice($charge, [], 31)->assertForbidden();                 // another student
        $this->invoice($charge, [], 2, 'teacher')->assertForbidden();       // teachers do not invoice
        $this->asGateway(31, 'student')->getJson("/api/charges/{$charge->id}/invoice")->assertForbidden();
        $this->asGateway(30, 'student')->getJson('/api/invoices')->assertForbidden();

        $id = $this->invoice($charge, [], 1, 'admin')->assertCreated()->json('invoice.id'); // admin on behalf of the student
        $this->asGateway(31, 'student')->getJson("/api/invoices/{$id}")->assertForbidden();
        $this->asGateway(31, 'student')->get("/api/invoices/{$id}/xml")->assertForbidden();
        $this->asGateway(30, 'student')->postJson("/api/invoices/{$id}/cancel", ['reason' => '02'])->assertForbidden();
        $this->asGateway(1, 'admin')->getJson('/api/invoices?q=EKU9003')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_fiscal_data_validation(): void
    {
        $charge = $this->paidReference();

        $this->invoice($charge, ['rfc' => 'ABC'])->assertUnprocessable()->assertJsonValidationErrors('rfc');
        $this->invoice($charge, ['zip' => '123'])->assertUnprocessable()->assertJsonValidationErrors('zip');
        $this->invoice($charge, ['regime' => '999'])->assertUnprocessable()->assertJsonValidationErrors('regime');
        $this->invoice($charge, ['amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        // Persona moral (12 chars) with a persona física regime.
        $this->invoice($charge, ['regime' => '605', 'cfdi_use' => 'D10'])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'persona moral'));
        // Persona física: D10 is not allowed for 626.
        $this->invoice($charge, ['rfc' => 'XOJI740919U48', 'name' => 'Ingrid Xodar Jimenez', 'zip' => '76028', 'regime' => '626', 'cfdi_use' => 'D10'])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'D10'));

        $this->assertSame([], $this->pacCalls);
    }

    public function test_generic_rfc_forces_regime_616_use_s01_and_issuer_zip(): void
    {
        $charge = $this->paidReference();

        $this->invoice($charge, ['rfc' => 'xaxx010101000', 'name' => 'Juan Pérez', 'zip' => null, 'regime' => null, 'cfdi_use' => null])
            ->assertCreated()
            ->assertJsonPath('invoice.receiver_regime', '616')
            ->assertJsonPath('invoice.cfdi_use', 'S01');

        $xml = $this->sentXml();
        $this->assertStringContainsString('Rfc="XAXX010101000" Nombre="JUAN PÉREZ" DomicilioFiscalReceptor="'.config('invoicing.issuer.zip').'" RegimenFiscalReceptor="616" UsoCFDI="S01"', $xml);
    }

    public function test_pac_rejection_is_reported_and_can_be_retried(): void
    {
        $charge = $this->paidReference();

        $this->pacMode = 'error';
        $this->invoice($charge)->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, '[CFDI40145]') && str_contains($m, 'Nombre del receptor'));

        $failed = Invoice::sole();
        $this->assertSame(Invoice::FAILED, $failed->status);
        $this->assertSame('CFDI40145', $failed->error_code);
        $this->assertNull($failed->uuid);
        $this->assertSame(Charge::APPROVED, $charge->fresh()->status); // the payment is untouched

        $this->pacMode = 'ok';
        $this->invoice($charge)->assertCreated();
        $this->assertSame(2, Invoice::count());
    }

    public function test_pac_down_answers_503(): void
    {
        $charge = $this->paidReference();
        $this->pacMode = 'down';

        $this->invoice($charge)->assertStatus(503);
        $this->assertSame(Invoice::FAILED, Invoice::sole()->status);
    }

    public function test_admin_cancels_and_a_new_invoice_can_be_issued(): void
    {
        $charge = $this->paidReference();
        $id = $this->invoice($charge)->assertCreated()->json('invoice.id');

        $this->asGateway(1, 'admin')->postJson("/api/invoices/{$id}/cancel", ['reason' => '01'])
            ->assertUnprocessable(); // 01 needs the replacement UUID
        $this->asGateway(1, 'admin')->postJson("/api/invoices/{$id}/cancel", ['reason' => '09'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->asGateway(1, 'admin')->postJson("/api/invoices/{$id}/cancel", ['reason' => '02'])
            ->assertOk()
            ->assertJsonPath('invoice.status', 'cancelled')
            ->assertJsonPath('invoice.cancel_reason', '02');

        $cancel = collect($this->pacCalls)->firstWhere('op', 'CancelaCFDI');
        $this->assertSame(['RfcEmisor' => config('invoicing.issuer.rfc'), 'FolioFiscal' => self::UUID, 'MotivoCancelacion' => '02', 'FolioFiscalSustitucion' => ''], $cancel['body']);

        $this->asGateway(1, 'admin')->postJson("/api/invoices/{$id}/cancel", ['reason' => '02'])->assertStatus(409);
        $this->asGateway(30, 'student')->getJson("/api/charges/{$charge->id}/invoice")->assertJsonPath('can_invoice', true);
        $this->invoice($charge)->assertCreated();
    }

    public function test_admin_checks_status_at_the_sat(): void
    {
        $charge = $this->paidReference();
        $id = $this->invoice($charge)->json('invoice.id');

        $this->asGateway(1, 'admin')->getJson("/api/invoices/{$id}/sat-status")
            ->assertOk()->assertJsonPath('EstadoSat', 'Vigente');
    }
}
