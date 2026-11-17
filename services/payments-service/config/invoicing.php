<?php

/*
| Electronic invoicing (CFDI 4.0) through the TimbraCFDI API (ProFact).
| Docs: https://developers.timbracfdi33.mx/reference
|
| The PAC seals the XML itself ("autosellado") with the issuer's CSD registered in its platform,
| so this service never handles the .cer/.key files: it builds the unsigned CFDI and sends it.
| Defaults point to the PAC test environment and to the test issuer of its documentation.
*/
return [
    'pac' => [
        // Test: https://pruebas.timbracfdi33.mx:1444/api · Production: https://timbracfdi33.mx:1444/api
        'url' => env('INVOICING_PAC_URL', 'https://pruebas.timbracfdi33.mx:1444/api'),
        'version' => env('INVOICING_PAC_VERSION', 'v2'),
        // Integrator token. The test one is public in the PAC docs; production gives you your own.
        'token' => env('INVOICING_PAC_TOKEN', 'mvpNUXmQfK8='),
        'timeout' => (int) env('INVOICING_PAC_TIMEOUT', 30),
        // CA bundle used to verify the PAC's HTTPS certificate. XAMPP's PHP ships without one (cURL error 60),
        // so the Mozilla bundle in resources/certs/cacert.pem is used by default. Set the variable to override it.
        'ca_bundle' => env('INVOICING_PAC_CA_BUNDLE') ?: (is_file(resource_path('certs/cacert.pem')) ? resource_path('certs/cacert.pem') : null),
    ],

    // Who issues the invoice (the school). Must be registered in the PAC with its CSD.
    'issuer' => [
        'rfc' => env('INVOICING_ISSUER_RFC', 'IIA040805DZ4'),
        'name' => env('INVOICING_ISSUER_NAME', 'INDISTRIA ILUMINADORA DE ALMACENES'),
        'regime' => env('INVOICING_ISSUER_REGIME', '626'),
        // LugarExpedicion: postal code of the place where the invoice is issued.
        'zip' => env('INVOICING_ISSUER_ZIP', '34000'),
    ],

    'series' => env('INVOICING_SERIES', 'ESC'),

    // SAT catalogs for the concept line.
    'product_code' => env('INVOICING_PRODUCT_CODE', '86121701'), // c_ClaveProdServ: educational services
    'unit_code' => env('INVOICING_UNIT_CODE', 'E48'),            // c_ClaveUnidad: service unit
    'unit_name' => 'Servicio',

    // VAT treatment of the fees: "iva" = the price already includes VAT at `iva_rate` (it is broken down),
    // "exento" = exempt (educational services under art. 15 LIVA).
    'tax_mode' => env('INVOICING_TAX_MODE', 'iva'),
    'iva_rate' => env('INVOICING_IVA_RATE', '0.160000'),

    // c_FormaPago by payment method of the reference.
    'payment_forms' => ['cash' => '01', 'card' => '04'],
];
