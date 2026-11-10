<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Xsd\XsdValidatorService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Emisión de punta a punta por POST /api/v1/documents con las referencias
 * de los Anexos 4.4: el XML FIRMADO que sale tiene que validar contra el
 * XSD 4.4 vigente (resources/xsd/v4.4). Hacienda está fakeada: no hay
 * envío real.
 */
function empresaEmisora(string $cedula = '3101234567'): string
{
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner-xsd', 'email' => 'xsd@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1',
        'legacy_timestamp' => time(), 'last_access' => time(),
        'password' => password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);

    $company = Company::create([
        'id' => $user->id, 'owner_user_id' => $user->id,
        'nombre' => 'ACME S.A.', 'tipo_cedula' => '02', 'cedula' => $cedula,
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
        'username' => 'cpj-3-101-234567@stag.comprobanteselectronicos.go.cr',
        'password' => 'atv-secret', 'p12_download_code' => 'p12code', 'pin' => '1234',
    ]);

    return $user->createToken('test', ['master'])->plainTextToken;
}

const CLAVE_ORIGINAL = '50601102600310123456700100001010000000001100000001';

/**
 * Emite el comprobante y devuelve [xml firmado, errores de XSD].
 *
 * @return array{0: string, 1: list<string>}
 */
function emitirYValidar(string $token, string $tipo, array $extra = []): array
{
    $response = emitir($token, $tipo, $extra);

    $response->assertStatus(201);

    $xmlFirmado = base64_decode($response->json('xmlFirmado'));
    $resultado = app(XsdValidatorService::class)->validate($xmlFirmado, $tipo);

    return [$xmlFirmado, $resultado['errors']];
}

/** Proveedor de una FEC: en la factura de compra es el EMISOR. */
const PROVEEDOR_FEC = [
    'emisor_nombre' => 'Proveedor Ejemplo',
    'emisor_tipo_identif' => '01',
    'emisor_num_identif' => '109990999',
    'emisor_provincia' => '2',
    'emisor_canton' => '10',
    // Sin el cero: la API lo rellena como los de la empresa.
    'emisor_distrito' => '1',
    'emisor_otras_senas' => 'Del parque 200 m norte',
    'codigo_actividad_receptor' => '6201.0',
];

/** POST /api/v1/documents con un comprobante base válido para el tipo. */
function emitir(string $token, string $tipo, array $extra = []): TestResponse
{
    (new CatalogSeeder)->run();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    // En la FEC el receptor es la empresa: el cliente manda al proveedor.
    $partes = $tipo === 'FEC' ? PROVEEDOR_FEC : [
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
        'receptor_email' => 'cliente@example.com',
    ];

    return test()->withToken($token)->postJson('/api/v1/documents', array_replace_recursive([
        'tipo' => $tipo,
        'environment' => 'stag',
        'fecha_emision' => '2026-10-17T10:00:00-06:00',
        'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 1130]],
        'detalles' => [[
            'codigoCABYS' => '8313100000100', 'cantidad' => 1, 'unidadMedida' => 'Sp',
            'detalle' => 'Servicio', 'precioUnitario' => 1000.00,
            'montoTotal' => 1000.00, 'subTotal' => 1000.00,
            'impuestoAsumidoEmisorFabrica' => 0, 'impuestoNeto' => 130.00,
            'montoTotalLinea' => 1130.00, 'baseImponible' => 1000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0, 'monto' => 130.00]],
        ]],
        'total_ventas' => 1000.00,
        'total_ventas_neta' => 1000.00,
        'total_comprobante' => 1130.00,
        'params' => [
            'codigo_actividad_emisor' => '6201.0',
            'total_serv_gravados' => 1000.00,
            'total_gravado' => 1000.00,
            'total_impuestos' => 130.00,
            'totalDesgloseImpuesto' => json_encode([
                ['Codigo' => '01', 'CodigoTarifaIVA' => '08', 'TotalMontoImpuesto' => 130],
            ]),
        ],
    ], $partes, $extra));
}

function referenciaParaXsd(string $tipoDoc, string $codigo, string $numero = CLAVE_ORIGINAL, array $extra = []): array
{
    return ['informacion_referencia' => [[
        'tipoDoc' => $tipoDoc,
        'numero' => $numero,
        'fechaEmision' => '2026-09-30T10:00:00-06:00',
        'codigo' => $codigo,
        'razon' => 'Referencia de prueba',
        ...$extra,
    ]]];
}

/** La referencia de una FEC al papel del proveedor (tipo 99 «Otros»). */
function referenciaPapelProveedor(): array
{
    return referenciaParaXsd('99', '04', '000318', ['tipoDocOtro' => 'Factura en papel del proveedor']);
}

test('el comprobante con la referencia nueva valida contra el XSD 4.4', function (string $tipo, string $tipoDoc, string $codigo) {
    [$xml, $errores] = emitirYValidar(empresaEmisora(), $tipo, referenciaParaXsd($tipoDoc, $codigo));

    expect($errores)->toBe([])
        ->and($xml)->toContain("<TipoDocIR>{$tipoDoc}</TipoDocIR>")
        ->toContain("<Codigo>{$codigo}</Codigo>");
})->with([
    'NC 13 sobre una FE' => ['NC', '01', '13'],
    'NC 14 sobre una FEE' => ['NC', '19', '14'],
    'ND 14' => ['ND', '01', '14'],
    'FE 16 que sustituye a un rechazado' => ['FE', '10', '16'],
    'FE 15 que sustituye por error material' => ['FE', '01', '15'],
    'FEC 04 sobre el comprobante de un proveedor no domiciliado' => ['FEC', '16', '04'],
]);

test('emisor y receptor con cédula jurídica alfanumérica validan contra el XSD 4.4', function () {
    [$xml, $errores] = emitirYValidar(empresaEmisora('3101A00001'), 'FE', [
        'receptor_tipo_identif' => '02',
        'receptor_num_identif' => '3101B00002',
    ]);

    expect($errores)->toBe([])
        ->and($xml)->toContain('<Numero>3101A00001</Numero>')
        ->toContain('<Numero>3101B00002</Numero>');

    // Posiciones 10-21 de la clave: la cédula con dos ceros (nota 4.1).
    preg_match('#<Clave>([^<]+)</Clave>#', $xml, $m);
    expect(substr($m[1], 9, 12))->toBe('003101A00001');
});

/*
 * Con dos o más medios de pago, Hacienda rechaza el comprobante si la suma de los TotalMedioPago no
 * coincide con TotalComprobante (Anexos 4.4, nota de TotalComprobante). Los dos son DecimalDineroType
 * (18,5 en el XSD): recortar el medio a 2 decimales hacía que la suma no cuadrara con un total de
 * 5 decimales, el caso normal de un precio con IVA.
 */
test('con varios medios de pago cada monto va con 5 decimales y la suma cuadra con el total', function (string $tipo, array $ajustes) {
    [$xml, $errores] = emitirYValidar(empresaEmisora(), $tipo, array_replace_recursive([
        'medios_pago' => [
            ['tipoMedioPago' => '01', 'totalMedioPago' => 2000],
            ['tipoMedioPago' => '06', 'totalMedioPago' => 2499.9877],
        ],
        'detalles' => [[
            'cantidad' => 3, 'precioUnitario' => 1327.43,
            'montoTotal' => 3982.29, 'subTotal' => 3982.29,
            'impuestoNeto' => 517.6977, 'montoTotalLinea' => 4499.9877, 'baseImponible' => 3982.29,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0, 'monto' => 517.6977]],
        ]],
        'total_ventas' => 3982.29,
        'total_ventas_neta' => 3982.29,
        'total_comprobante' => 4499.9877,
        'params' => [
            'total_serv_gravados' => 3982.29,
            'total_gravado' => 3982.29,
            'total_impuestos' => 517.6977,
            'totalDesgloseImpuesto' => json_encode([
                ['Codigo' => '01', 'CodigoTarifaIVA' => '08', 'TotalMontoImpuesto' => 517.6977],
            ]),
        ],
    ], $ajustes));

    expect($errores)->toBe([]);

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $medios = array_map(fn (DOMNode $n): string => $n->textContent, iterator_to_array($dom->getElementsByTagName('TotalMedioPago')));
    $total = $dom->getElementsByTagName('TotalComprobante')->item(0)->textContent;

    expect($medios)->toBe(['2000.00000', '2499.98770'])
        ->and(bcadd($medios[0], $medios[1], 5))->toBe(bcadd($total, '0', 5));
})->with([
    'FE' => ['FE', []],
    'TE' => ['TE', []],
    'NC' => ['NC', referenciaParaXsd('01', '01')],
    'ND' => ['ND', referenciaParaXsd('01', '02')],
    // La FEC 4.4 exige InformacionReferencia (tipo 16: comprobante del proveedor no domiciliado).
    'FEC' => ['FEC', referenciaParaXsd('16', '04', 'F-001')],
    'FEE' => ['FEE', []],
]);

test('la FEE con una línea gravada valida contra el XSD 4.4 y no lleva ImpuestoNeto', function () {
    // La LineaDetalle de la FEE no tiene ImpuestoNeto: aunque venga en el cuerpo (130), no se escribe.
    [$xml, $errores] = emitirYValidar(empresaEmisora(), 'FEE');

    expect($errores)->toBe([])
        ->and($xml)->not->toContain('<ImpuestoNeto>')
        ->toContain('<Monto>130</Monto>');
});

/*
 * FEC (#72): la empresa COMPRA, así que va como Receptor y es quien firma
 * (Anexos 4.4, pág. 60); el Emisor es el proveedor. Con los papeles al
 * revés Hacienda la rechaza con -60 «El contribuyente que firma la factura
 * electrónica no es el receptor».
 */
test('la FEC lleva a la empresa como receptor y al proveedor como emisor', function () {
    [$xml, $errores] = emitirYValidar(empresaEmisora(), 'FEC', referenciaPapelProveedor());

    expect($errores)->toBe([]);
    preg_match('#<Emisor>(.*?)</Emisor>#s', $xml, $emisor);
    preg_match('#<Receptor>(.*?)</Receptor>#s', $xml, $receptor);
    expect($emisor[1])->toContain('<Nombre>Proveedor Ejemplo</Nombre>')->toContain('<Numero>109990999</Numero>')
        ->and($receptor[1])->toContain('<Nombre>ACME S.A.</Nombre>')->toContain('<Numero>3101234567</Numero>')
        ->and($emisor[1])->toContain('<Canton>10</Canton>')->toContain('<Distrito>01</Distrito>')
        ->and($xml)->toContain('<CodigoActividadReceptor>6201.0</CodigoActividadReceptor>')
        // La clave sigue siendo de la empresa: es su numeración (nota 4.1).
        ->and($xml)->toMatch('#<Clave>\d{9}003101234567#');

    // El JSON de /recepcion nombra a las mismas partes que el XML.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'recepcion')
        && $request['emisor'] === ['tipoIdentificacion' => '01', 'numeroIdentificacion' => '109990999']
        && $request['receptor'] === ['tipoIdentificacion' => '02', 'numeroIdentificacion' => '3101234567']);
});

test('la FEC a un proveedor sin correo cierra el Emisor y valida contra el XSD', function () {
    // Sin correo ni actividad: el proveedor puede no estar inscrito.
    [$xml, $errores] = emitirYValidar(empresaEmisora(), 'FEC', referenciaParaXsd('16', '11', 'INV-1') + [
        'params' => ['codigo_actividad_emisor' => null],
    ]);

    preg_match('#<Emisor>(.*?)</Emisor>#s', $xml, $emisor);
    expect($errores)->toBe([])
        ->and($emisor[1] ?? null)->not->toBeNull()->not->toContain('<CorreoElectronico>')
        ->and($xml)->not->toContain('<CodigoActividadEmisor>');
});

test('la FEC a un extranjero no domiciliado no pide ubicación y va con la referencia 16', function () {
    [$xml, $errores] = emitirYValidar(empresaEmisora(), 'FEC', referenciaParaXsd('16', '11', 'INV-2026-0042') + [
        'emisor_nombre' => 'Foreign Supplier LLC',
        'emisor_tipo_identif' => '05',
        'emisor_num_identif' => 'US123456',
        'emisor_provincia' => null,
        'emisor_canton' => null,
        'emisor_distrito' => null,
        'emisor_otras_senas' => null,
        'emisor_otras_senas_extranjero' => '100 Main St, Springfield',
    ]);

    expect($errores)->toBe([])
        ->and($xml)->toContain('<Tipo>05</Tipo>')
        ->toContain('<OtrasSenasExtranjero>100 Main St, Springfield</OtrasSenasExtranjero>');
});

test('la FEC rechaza los datos que no van', function (array $cambio, string $campo) {
    emitir(empresaEmisora(), 'FEC', referenciaPapelProveedor() + $cambio)
        ->assertStatus(422)
        ->assertJsonValidationErrors($campo);
})->with([
    'sin proveedor' => [['emisor_nombre' => null], 'emisor_nombre'],
    'el proveedor como receptor' => [['receptor_nombre' => 'Proveedor Ejemplo'], 'receptor_nombre'],
    'proveedor nacional sin ubicación' => [['emisor_provincia' => null], 'emisor_provincia'],
    'sin la actividad de la empresa' => [['codigo_actividad_receptor' => null], 'codigo_actividad_receptor'],
    'no domiciliado sin la referencia 16' => [['emisor_tipo_identif' => '05', 'emisor_num_identif' => 'US1'], 'informacion_referencia'],
    // Nota al pie 18: el 06 solo vende bienes usados (condición 13); -502 en Hacienda.
    'no contribuyente sin la condición 13' => [['emisor_tipo_identif' => '06', 'emisor_num_identif' => 'NC1'], 'condicion_venta'],
    'condición 13 con un proveedor inscrito' => [['condicion_venta' => '13'], 'condicion_venta'],
    // Tamaños del XSD 4.4.
    'nombre del proveedor de menos de 5' => [['emisor_nombre' => 'Ana'], 'emisor_nombre'],
    'actividad de la empresa sin 6 caracteres' => [['codigo_actividad_receptor' => '6201'], 'codigo_actividad_receptor'],
    'señas de menos de 5' => [['emisor_otras_senas' => 'Ahí'], 'emisor_otras_senas'],
    // `params` gana en el payload, así que se valida su valor.
    'proveedor 05 escondido en params' => [['params' => ['emisor_tipo_identif' => '05']], 'informacion_referencia'],
]);

test('una referencia de más de 10 años no se acepta', function () {
    emitir(empresaEmisora(), 'NC', referenciaParaXsd('01', '01', CLAVE_ORIGINAL, [
        'fechaEmision' => now()->subYears(10)->subDays(2)->toIso8601String(),
    ]))->assertStatus(422)->assertJsonValidationErrors('informacion_referencia.0.fechaEmision');
});

test('la FEC a un no contribuyente va con la condición 13 y sin ubicación', function () {
    [$xml, $errores] = emitirYValidar(empresaEmisora(), 'FEC', referenciaPapelProveedor() + [
        'condicion_venta' => '13',
        'emisor_tipo_identif' => '06',
        'emisor_num_identif' => 'NC0000000001',
        'emisor_provincia' => null,
        'emisor_canton' => null,
        'emisor_distrito' => null,
        'emisor_otras_senas' => null,
    ]);

    expect($errores)->toBe([])
        ->and($xml)->toContain('<CondicionVenta>13</CondicionVenta>')
        ->toContain('<Tipo>06</Tipo>');
});

test('la condición 13 solo se usa en la FEC', function () {
    emitir(empresaEmisora(), 'FE', ['condicion_venta' => '13'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('condicion_venta');
});

test('en la FEC no se acepta otro receptor ni por params', function () {
    emitir(empresaEmisora(), 'FEC', referenciaPapelProveedor() + [
        'params' => ['receptor_num_identif' => '999999999'],
    ])->assertStatus(422)->assertJsonValidationErrors('receptor_num_identif');
});
