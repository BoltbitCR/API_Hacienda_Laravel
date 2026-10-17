<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Emisión de comprobantes por la API v1: ejercita el flujo completo
 * clave → XML (generador v4.4) → firma XAdES → token → envío → persistencia,
 * con Hacienda mockeado vía Http::fake.
 */
function companyWithCredentials(): array
{
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner', 'email' => 'owner@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1',
        'legacy_timestamp' => time(), 'last_access' => time(),
        'password' => password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);

    $company = Company::create([
        'id' => $user->id, 'owner_user_id' => $user->id,
        'nombre' => 'ACME S.A.', 'tipo_cedula' => '02', 'cedula' => '3101234567',
        'nombre_comercial' => 'ACME', 'email' => 'facturas@acme.cr',
        'id_provincia' => '1', 'id_canton' => '01', 'id_distrito' => '01', 'id_barrio' => '01',
        'sennas' => 'Oficinas centrales', 'tel_cod_pais' => '506', 'tel_numero' => '22001100',
        'env' => 'api-stag', 'situacion' => 'normal', 'tipo_cambio' => '1',
    ]);

    // Certificado .p12 de prueba (el mismo del corpus golden).
    $dir = storage_path('app/legacy-files/'.$user->id.'/hacienda');
    @mkdir($dir, 0775, true);
    copy(base_path('legacy/golden/test-cert.p12'), $dir.'/cert.p12');

    StoredFile::create([
        'user_id' => $user->id, 'name' => 'cert.p12', 'download_code' => 'p12code',
        'type' => 'hacienda', 'path' => 'legacy-files/'.$user->id.'/hacienda/cert.p12',
    ]);

    HaciendaCredential::create([
        'company_id' => $company->id, 'environment' => 'stag',
        'username' => 'cpf-03-0101-234567@stag.comprobanteselectronicos.go.cr',
        'password' => 'atv-secret', 'p12_download_code' => 'p12code', 'pin' => '1234',
    ]);

    return [$user, $company];
}

test('emite una factura electrónica de punta a punta', function () {
    (new CatalogSeeder)->run();
    [$user] = companyWithCredentials();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    $payload = [
        'tipo' => 'FE',
        'environment' => 'stag',
        'codigoSeguridad' => '12345678',
        'codigo_actividad_emisor' => '741203',
        'fecha_emision' => '2024-02-07T12:00:00-06:00',
        'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 11300.00]],
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '108880777',
        'receptor_email' => 'cliente@example.com',
        'cod_moneda' => 'CRC', 'tipo_cambio' => '1.00',
        'total_serv_gravados' => '10000.00', 'total_gravados' => '10000.00',
        'total_ventas' => '10000.00', 'total_ventas_neta' => '10000.00',
        'total_impuestos' => '1300.00', 'total_comprobante' => '11300.00',
        'detalles' => [[
            'codigoCABYS' => '8399000000000', 'cantidad' => 1, 'unidadMedida' => 'Sp',
            'detalle' => 'Servicio', 'precioUnitario' => 10000.00, 'montoTotal' => 10000.00,
            'subTotal' => 10000.00, 'baseImponible' => 10000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.00, 'monto' => 1300.00]],
            'impuestoAsumidoEmisorFabrica' => 0.00,
            'impuestoNeto' => 1300.00, 'montoTotalLinea' => 11300.00,
        ]],
    ];

    $response = $this->withToken($token)->postJson('/api/v1/documents', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure(['clave', 'consecutivo', 'xml', 'xmlFirmado', 'envio', 'document_id']);

    // Clave de 50 dígitos y comprobante persistido.
    expect(strlen($response->json('clave')))->toBe(50);
    $this->assertDatabaseHas('documents', [
        'clave' => $response->json('clave'),
        'tipo_documento' => 'FE',
        'estado' => 'enviado',
    ]);

    // La firma es un XML válido con Signature XAdES.
    $signed = base64_decode($response->json('xmlFirmado'));
    expect($signed)->toContain('<ds:Signature')->toContain('SignaturePolicyIdentifier');

    // Se llamó al IDP y a recepción de Hacienda.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'idp'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'recepcion'));
});

test('emitir sin certificado devuelve 422 con mensaje claro', function () {
    (new CatalogSeeder)->run();

    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner2', 'email' => 'o2@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1', 'legacy_timestamp' => time(),
        'last_access' => time(), 'password' => password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);
    Company::create([
        'id' => $user->id, 'owner_user_id' => $user->id, 'nombre' => 'SinCert',
        'tipo_cedula' => '02', 'cedula' => '3109999999', 'env' => 'api-stag',
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/documents', [
        'tipo' => 'FE', 'environment' => 'stag', 'condicion_venta' => '01',
        'detalles' => [], 'total_ventas' => '0', 'total_ventas_neta' => '0', 'total_comprobante' => '0',
    ])->assertStatus(422)->assertJsonStructure(['message']);
});

test('normaliza los códigos de ubicación del emisor al ancho del esquema', function () {
    (new CatalogSeeder)->run();
    [$user, $company] = companyWithCredentials();

    // El catálogo legacy mezcla anchos: "015" (3 dígitos) y "4" (1 dígito).
    $company->update(['id_provincia' => '2', 'id_canton' => '015', 'id_distrito' => '4', 'id_barrio' => '08']);

    // En v4.4 Barrio es texto (minLength 5): sale el nombre del catálogo, no el código.
    DB::table('codificacion_mh')->insert([
        'id_provincia' => '2', 'nombre_provincia' => 'Alajuela',
        'id_canton' => '015', 'nombre_canton' => 'GUATUSO',
        'id_distrito' => '4', 'nombre_distrito' => 'KATIRA',
        'id_barrio' => '08', 'nombre_barrio' => 'Llano Bonito 1',
    ]);

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/documents', [
        'tipo' => 'FE', 'environment' => 'stag', 'codigoSeguridad' => '12345678',
        'codigo_actividad_emisor' => '741203',
        'fecha_emision' => '2024-02-07T12:00:00-06:00', 'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 11300.00]],
        'receptor_nombre' => 'Cliente ABC', 'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '108880777', 'receptor_email' => 'cliente@example.com',
        'cod_moneda' => 'CRC', 'tipo_cambio' => '1.00',
        'total_serv_gravados' => '10000.00', 'total_gravados' => '10000.00',
        'total_ventas' => '10000.00', 'total_ventas_neta' => '10000.00',
        'total_impuestos' => '1300.00', 'total_comprobante' => '11300.00',
        'detalles' => [[
            'codigoCABYS' => '8399000000000', 'cantidad' => 1, 'unidadMedida' => 'Sp',
            'detalle' => 'Servicio', 'precioUnitario' => 10000.00, 'montoTotal' => 10000.00,
            'subTotal' => 10000.00, 'baseImponible' => 10000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.00, 'monto' => 1300.00]],
            'impuestoAsumidoEmisorFabrica' => 0.00,
            'impuestoNeto' => 1300.00, 'montoTotalLinea' => 11300.00,
        ]],
    ]);

    $response->assertStatus(201);

    $xml = base64_decode($response->json('xml'));

    expect($xml)->toContain('<Provincia>2</Provincia>')
        ->toContain('<Canton>15</Canton>')
        ->toContain('<Distrito>04</Distrito>')
        ->toContain('<Barrio>Llano Bonito 1</Barrio>');
});

test('el XML generado valida contra el XSD oficial v4.4', function () {
    (new CatalogSeeder)->run();
    [$user, $company] = companyWithCredentials();

    // Réplica del caso real: códigos legacy de ancho mixto y venta a crédito.
    $company->update(['id_provincia' => '2', 'id_canton' => '015', 'id_distrito' => '04', 'id_barrio' => '08']);
    DB::table('codificacion_mh')->insert([
        'id_provincia' => '2', 'nombre_provincia' => 'Alajuela',
        'id_canton' => '015', 'nombre_canton' => 'GUATUSO',
        'id_distrito' => '04', 'nombre_distrito' => 'KATIRA',
        'id_barrio' => '08', 'nombre_barrio' => 'Llano Bonito 1',
    ]);

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    // Mismo shape que envía factu-hacienda (crédito con plazo, IVA incluido).
    $response = $this->withToken($token)->postJson('/api/v1/documents', [
        'tipo' => 'FE',
        'environment' => 'stag',
        'fecha_emision' => '2026-07-02T16:52:05-06:00',
        'condicion_venta' => '02',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 222]],
        'detalles' => [[
            'codigoCABYS' => '8313100000100', 'cantidad' => 1, 'unidadMedida' => 'Sp',
            'detalle' => 'Servicio de prueba', 'precioUnitario' => 196.46018,
            'montoTotal' => 196.46018, 'subTotal' => 196.46018,
            'impuestoAsumidoEmisorFabrica' => 0, 'impuestoNeto' => 25.53982,
            'montoTotalLinea' => 222, 'baseImponible' => 196.46018,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13, 'monto' => 25.53982]],
        ]],
        'total_ventas' => 196.46018,
        'total_ventas_neta' => 196.46018,
        'total_comprobante' => 222,
        'params' => [
            'codigo_actividad_emisor' => '6201.0',
            'plazo_credito' => '30',
            'total_serv_gravados' => 196.46018,
            'total_gravados' => 196.46018,
            'total_impuestos' => 25.53982,
            'totalDesgloseImpuesto' => json_encode([
                ['Codigo' => '01', 'CodigoTarifaIVA' => '08', 'TotalMontoImpuesto' => 25.53982],
            ]),
        ],
        'receptor_nombre' => 'Jefte',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
        'receptor_email' => 'cliente@example.com',
    ]);

    $response->assertStatus(201);

    $xmlCrudo = base64_decode($response->json('xml'));
    expect($xmlCrudo)->toContain('<CodigoActividadEmisor>6201.0</CodigoActividadEmisor>')
        ->toContain('<TotalDesgloseImpuesto>');

    $dom = new DOMDocument;
    $dom->loadXML(base64_decode($response->json('xml')));

    libxml_use_internal_errors(true);
    $valid = $dom->schemaValidate(base_path('legacy/www/xsd/FacturaElectronica_V4.4-noSign.xsd'));
    $errores = collect(libxml_get_errors())
        ->map(fn ($e) => trim($e->message).' (línea '.$e->line.')')
        ->implode("\n");
    libxml_clear_errors();

    $this->assertTrue($valid, "El XML no valida contra el XSD v4.4:\n".$errores);
});

test('una línea con tarifa 0 lleva el nodo Tarifa', function () {
    (new CatalogSeeder)->run();
    [$user] = companyWithCredentials();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    // Tarifa exenta (código 10): Hacienda exige <Tarifa>0</Tarifa>, no su
    // ausencia (rechazos -45 y -505).
    $response = $this->withToken($token)->postJson('/api/v1/documents', [
        'tipo' => 'FE',
        'environment' => 'stag',
        'fecha_emision' => '2026-07-02T16:52:05-06:00',
        'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 1000]],
        'detalles' => [[
            'codigoCABYS' => '0111100000100', 'cantidad' => 1, 'unidadMedida' => 'Unid',
            'detalle' => 'Producto exento', 'precioUnitario' => 1000.00,
            'montoTotal' => 1000.00, 'subTotal' => 1000.00,
            'impuestoAsumidoEmisorFabrica' => 0, 'impuestoNeto' => 0.00,
            'montoTotalLinea' => 1000.00, 'baseImponible' => 1000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '10', 'tarifa' => 0.0, 'monto' => 0.00]],
        ]],
        'total_ventas' => 1000.00,
        'total_ventas_neta' => 1000.00,
        'total_comprobante' => 1000.00,
        'params' => [
            'codigo_actividad_emisor' => '6201.0',
            'total_merc_exenta' => 1000.00,
            'total_exento' => 1000.00,
            'total_impuestos' => 0.00,
            'totalDesgloseImpuesto' => json_encode([
                ['Codigo' => '01', 'CodigoTarifaIVA' => '10', 'TotalMontoImpuesto' => 0],
            ]),
        ],
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
        'receptor_email' => 'cliente@example.com',
    ]);

    $response->assertStatus(201);

    $xml = base64_decode($response->json('xml'));
    expect($xml)->toContain('<CodigoTarifaIVA>10</CodigoTarifaIVA>')
        ->toContain('<Tarifa>0</Tarifa>');

    $dom = new DOMDocument;
    $dom->loadXML($xml);

    libxml_use_internal_errors(true);
    $valid = $dom->schemaValidate(base_path('legacy/www/xsd/FacturaElectronica_V4.4-noSign.xsd'));
    $errores = collect(libxml_get_errors())
        ->map(fn ($e) => trim($e->message).' (línea '.$e->line.')')
        ->implode("\n");
    libxml_clear_errors();

    $this->assertTrue($valid, "El XML no valida contra el XSD v4.4:\n".$errores);
});

test('una empresa con cédula jurídica alfanumérica emite una FE válida', function () {
    (new CatalogSeeder)->run();
    [$user, $company] = companyWithCredentials();
    // Como la puede guardar un cliente: con guiones y en minúsculas.
    $company->update(['cedula' => '3-101-a00001']);

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/documents', [
        'tipo' => 'FE',
        'environment' => 'stag',
        'fecha_emision' => '2026-07-02T16:52:05-06:00',
        'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 1000]],
        'detalles' => [[
            'codigoCABYS' => '0111100000100', 'cantidad' => 1, 'unidadMedida' => 'Unid',
            'detalle' => 'Producto exento', 'precioUnitario' => 1000.00,
            'montoTotal' => 1000.00, 'subTotal' => 1000.00,
            'impuestoAsumidoEmisorFabrica' => 0, 'impuestoNeto' => 0.00,
            'montoTotalLinea' => 1000.00, 'baseImponible' => 1000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '10', 'tarifa' => 0.0, 'monto' => 0.00]],
        ]],
        'total_ventas' => 1000.00,
        'total_ventas_neta' => 1000.00,
        'total_comprobante' => 1000.00,
        'params' => [
            'codigo_actividad_emisor' => '6201.0',
            'total_merc_exenta' => 1000.00,
            'total_exento' => 1000.00,
            'total_impuestos' => 0.00,
            'totalDesgloseImpuesto' => json_encode([
                ['Codigo' => '01', 'CodigoTarifaIVA' => '10', 'TotalMontoImpuesto' => 0],
            ]),
        ],
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
    ])->assertStatus(201);

    // Posiciones 10-21 de la clave: la cédula con dos ceros (nota 4.1).
    expect(substr($response->json('clave'), 9, 12))->toBe('003101A00001');

    $xml = base64_decode($response->json('xml'));
    expect($xml)->toContain('<Numero>3101A00001</Numero>');

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    libxml_use_internal_errors(true);
    $valid = $dom->schemaValidate(base_path('resources/xsd/v4.4/FacturaElectronica_V4.4-noSign.xsd'));
    $errores = collect(libxml_get_errors())->map(fn ($e) => trim($e->message))->implode("\n");
    libxml_clear_errors();
    $this->assertTrue($valid, "El XML no valida contra el XSD v4.4:\n".$errores);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'recepcion')
        && $request['emisor']['numeroIdentificacion'] === '3101A00001');
});

test('un código de referencia fuera del catálogo devuelve 422 y no llega a Hacienda', function () {
    (new CatalogSeeder)->run();
    [$user] = companyWithCredentials();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $token = $user->createToken('test', ['master'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/documents', [
        'tipo' => 'NC',
        'environment' => 'stag',
        'fecha_emision' => '2026-07-02T16:52:05-06:00',
        'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 1000]],
        'detalles' => [[
            'codigoCABYS' => '0111100000100', 'cantidad' => 1, 'unidadMedida' => 'Unid',
            'detalle' => 'Producto exento', 'precioUnitario' => 1000.00,
            'montoTotal' => 1000.00, 'subTotal' => 1000.00,
            'impuestoAsumidoEmisorFabrica' => 0, 'impuestoNeto' => 0.00,
            'montoTotalLinea' => 1000.00, 'baseImponible' => 1000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '10', 'tarifa' => 0.0, 'monto' => 0.00]],
        ]],
        'total_ventas' => 1000.00,
        'total_ventas_neta' => 1000.00,
        'total_comprobante' => 1000.00,
        'params' => [
            'codigo_actividad_emisor' => '6201.0',
            'informacion_referencia' => json_encode([[
                'tipoDoc' => '01',
                'numero' => '50601102600310100000100100001010000000001100000001',
                'fechaEmision' => '2026-07-01T10:00:00-06:00',
                'codigo' => '03',
                'razon' => 'Prueba',
            ]]),
        ],
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
    ])->assertStatus(422)->assertJsonValidationErrors('informacion_referencia.0.codigo');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'recepcion'));
});
