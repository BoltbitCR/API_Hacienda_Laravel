<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Xsd\XsdValidatorService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\Http;

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
    (new CatalogSeeder)->run();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    $response = test()->withToken($token)->postJson('/api/v1/documents', array_replace_recursive([
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
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
        'receptor_email' => 'cliente@example.com',
    ], $extra));

    $response->assertStatus(201);

    $xmlFirmado = base64_decode($response->json('xmlFirmado'));
    $resultado = app(XsdValidatorService::class)->validate($xmlFirmado, $tipo);

    return [$xmlFirmado, $resultado['errors']];
}

function referencia(string $tipoDoc, string $codigo, string $numero = CLAVE_ORIGINAL): array
{
    return ['informacion_referencia' => [[
        'tipoDoc' => $tipoDoc,
        'numero' => $numero,
        'fechaEmision' => '2026-09-30T10:00:00-06:00',
        'codigo' => $codigo,
        'razon' => 'Referencia de prueba',
    ]]];
}

test('el comprobante con la referencia nueva valida contra el XSD 4.4', function (string $tipo, string $tipoDoc, string $codigo) {
    [$xml, $errores] = emitirYValidar(empresaEmisora(), $tipo, referencia($tipoDoc, $codigo));

    expect($errores)->toBe([])
        ->and($xml)->toContain("<TipoDocIR>{$tipoDoc}</TipoDocIR>")
        ->toContain("<Codigo>{$codigo}</Codigo>");
})->with([
    'NC 13 sobre una FE' => ['NC', '01', '13'],
    'NC 14 sobre una FEE' => ['NC', '19', '14'],
    'ND 14' => ['ND', '01', '14'],
    'FE 16 que sustituye a un rechazado' => ['FE', '10', '16'],
    'FE 15 que sustituye por error material' => ['FE', '01', '15'],
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
