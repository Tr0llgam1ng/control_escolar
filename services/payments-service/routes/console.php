<?php

use App\Invoicing\CfdiBuilder;
use App\Invoicing\PacException;
use App\Invoicing\TimbraCfdiClient;
use Illuminate\Support\Facades\Artisan;

Artisan::command('service:info', function () {
    $this->info(config('app.name').' — '.config('app.url'));
})->purpose('Show service name and URL');

/*
| php artisan invoicing:check            → network diagnosis + token + stamps available for the issuer
| php artisan invoicing:check --stamp    → also stamps a $1.16 test invoice to the SAT test receiver EKU9003173C9
*/
Artisan::command('invoicing:check {--stamp : Stamp a test invoice (only against the test environment)}', function (TimbraCfdiClient $pac, CfdiBuilder $builder) {
    $url = (string) config('invoicing.pac.url');
    $issuer = config('invoicing.issuer.rfc');
    $this->line("PAC: {$url} ({$issuer})");

    // ---- 1. Network diagnosis, step by step, so a failure says WHERE it fails.
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

    $ip = gethostbyname($host);
    if ($ip === $host) {
        $this->error("[1/3] DNS: no se pudo resolver {$host}. Revisa tu conexión a internet o el DNS.");

        return 1;
    }
    $this->info("[1/3] DNS: {$host} → {$ip}");

    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (! $socket) {
        $this->error("[2/3] Puerto {$port}: sin conexión ({$errno} {$errstr}).");
        $this->warn("      Tu red o firewall bloquea el puerto {$port}. Prueba con otra red (por ejemplo, datos del celular) o permite la salida a ese puerto.");

        return 1;
    }
    fclose($socket);
    $this->info("[2/3] Puerto {$port}: abierto");

    $caBundle = config('invoicing.pac.ca_bundle');
    $cainfo = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
    $this->line('[3/3] SSL: '.($caBundle ? "INVOICING_PAC_CA_BUNDLE={$caBundle}" : 'curl.cainfo='.($cainfo ?: '(vacío)')).' · '.php_ini_loaded_file());

    // ---- 2. API calls.
    $report = function (string $label, PacException $e) {
        $this->error("{$label}: {$e->describe()}");
        if ($e->detail) {
            $this->line("      Detalle: {$e->detail}");
            if (str_contains($e->detail, 'cURL error 60') || str_contains($e->detail, 'certificate')) {
                $this->warn('      No se pudo validar el certificado HTTPS del PAC con '.(config('invoicing.pac.ca_bundle') ?: 'los certificados de PHP').'.');
                $this->warn('      Verifica que exista resources/certs/cacert.pem o descarga uno nuevo de https://curl.se/ca/cacert.pem');
                $this->warn('      y apunta a él con INVOICING_PAC_CA_BUNDLE en el .env.');
            } elseif (str_contains($e->detail, 'cURL error 28')) {
                $this->warn('      Tiempo de espera agotado: la red es lenta o bloquea la conexión. Prueba con otra red o sube INVOICING_PAC_TIMEOUT.');
            }
        }
    };

    try {
        $stamps = $pac->availableStamps($issuer);
        $this->info('Token OK. Timbres disponibles: '.($stamps['TimbresDisponibles'] ?? '?').' de '.($stamps['TimbresContratados'] ?? '?'));
    } catch (PacException $e) {
        $report('Consulta de timbres', $e);
        if ($e->unreachable) {
            return 1;
        }
    }

    if (! $this->option('stamp')) {
        return 0;
    }
    if (! str_contains($url, 'pruebas.')) {
        $this->error('--stamp solo se permite contra el ambiente de pruebas.');

        return 1;
    }

    $built = $builder->build([
        'series' => 'TEST', 'folio' => (string) time(),
        'date' => now(config('payments.timezone'))->subMinute()->format('Y-m-d\TH:i:s'),
        'payment_form' => '01', 'total_cents' => 116, 'description' => 'Prueba de conexión', 'item_id' => 'test',
    ], ['rfc' => 'EKU9003173C9', 'name' => 'ESCUELA KEMPER URGATE', 'zip' => '42501', 'regime' => '603', 'cfdi_use' => 'G03']);

    try {
        $result = $pac->stamp($built['xml'], 'invoicing-check');
        $this->info("Timbrado OK. UUID {$result['uuid']}");
    } catch (PacException $e) {
        $report('Timbrado rechazado', $e);
        if (! $e->unreachable) {
            $this->line($built['xml']);
        }

        return 1;
    }

    return 0;
})->purpose('Check the connection with the invoicing PAC (TimbraCFDI)');
