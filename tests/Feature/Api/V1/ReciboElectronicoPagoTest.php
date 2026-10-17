<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Xsd\XsdValidatorService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Recibo Electrónico de Pago (REP) por POST /api/v1/documents (Anexos 4.4):
 * cancela una factura con condición 08/10, va con condición 09/11 y la
 * referencia con el código 17. El XML firmado valida contra el XSD.
 */
function repEmpresa(): string
{
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner-rep', 'email' => 'rep@example.com',
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

const CLAVE_FACTURA_ESTADO = '50601092600310123456700100001010000000009100000009';

/** Cuerpo de un REP que paga ₡1.130 de una factura 08 al Estado. */
function repCuerpo(array $overrides = []): array
{
    return array_replace([
        'tipo' => 'REP',
        'environment' => 'stag',
        'fecha_emision' => '2026-10-17T10:00:00-06:00',
        'condicion_venta' => '09',
        'medios_pago' => [['tipoMedioPago' => '04', 'totalMedioPago' => 1130]],
        'detalles' => [[
            'detalle' => 'Pago de la factura 9 (servicios al Estado)',
            'montoTotal' => 1000.00, 'subTotal' => 1000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0, 'monto' => 130.00]],
            'impuestoNeto' => 130.00, 'montoTotalLinea' => 1130.00,
        ]],
        'total_ventas' => 1000.00,
        'total_ventas_neta' => 1000.00,
        'total_comprobante' => 1130.00,
        'params' => [
            'total_impuestos' => 130.00,
            'totalDesgloseImpuesto' => json_encode([['Codigo' => '01', 'CodigoTarifaIVA' => '08', 'TotalMontoImpuesto' => 130]]),
        ],
        'receptor_nombre' => 'Ministerio de Ejemplo',
        'receptor_tipo_identif' => '02',
        'receptor_num_identif' => '2100042000',
        'informacion_referencia' => [[
            'tipoDoc' => '01',
            'numero' => CLAVE_FACTURA_ESTADO,
            'fechaEmision' => '2026-09-15T10:00:00-06:00',
            'codigo' => '17',
            'razon' => 'Pago de la factura de servicios al Estado',
        ]],
    ], $overrides);
}

function repEmitir(array $cuerpo, ?string $token = null)
{
    (new CatalogSeeder)->run();

    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer'], 200),
        '*recepcion*' => Http::response('', 202),
    ]);

    return test()->withToken($token ?? repEmpresa())->postJson('/api/v1/documents', $cuerpo);
}

test('el REP se emite y su XML firmado valida contra el XSD', function (string $condicion) {
    $response = repEmitir(repCuerpo(['condicion_venta' => $condicion]))->assertStatus(201);

    // Tipo 10 en la clave y en el consecutivo (nota 3).
    expect(substr($response->json('consecutivo'), 8, 2))->toBe('10');

    $xml = base64_decode($response->json('xmlFirmado'));
    $resultado = app(XsdValidatorService::class)->validate($xml, 'REP');

    expect($resultado['errors'])->toBe([])
        ->and($xml)->toContain('<ReciboElectronicoPago')
        ->toContain("<CondicionVenta>{$condicion}</CondicionVenta>")
        ->toContain('<Codigo>17</Codigo>')
        ->toContain('<Numero>'.CLAVE_FACTURA_ESTADO.'</Numero>');
})->with(['09', '11']);

test('el REP referencia a otro REP con TipoDocIR 20', function () {
    $cuerpo = repCuerpo();
    $cuerpo['informacion_referencia'][0]['tipoDoc'] = '20';

    $xml = base64_decode(repEmitir($cuerpo)->assertStatus(201)->json('xmlFirmado'));

    expect(app(XsdValidatorService::class)->validate($xml, 'REP')['errors'])->toBe([]);
});

test('el REP rechaza lo que el anexo no le permite', function (array $overrides, string $campo) {
    $cuerpo = repCuerpo();
    foreach ($overrides as $clave => $valor) {
        data_set($cuerpo, $clave, $valor);
    }

    repEmitir($cuerpo)->assertStatus(422)->assertJsonValidationErrors($campo);
})->with([
    'condición de venta 01' => [['condicion_venta' => '01'], 'condicion_venta'],
    'sin referencia' => [['informacion_referencia' => null], 'informacion_referencia'],
    'TipoDocIR 03 (NC)' => [['informacion_referencia.0.tipoDoc' => '03'], 'informacion_referencia.0.tipoDoc'],
]);

test('fuera del REP no se aceptan el 17 ni las condiciones 09/11', function () {
    $token = repEmpresa();

    $fe = repCuerpo(['tipo' => 'FE', 'condicion_venta' => '09']);
    unset($fe['informacion_referencia']);
    repEmitir($fe, $token)->assertStatus(422)->assertJsonValidationErrors('condicion_venta');

    repEmitir(repCuerpo(['tipo' => 'NC', 'condicion_venta' => '01']), $token)
        ->assertStatus(422)->assertJsonValidationErrors('informacion_referencia.0.codigo');
});
