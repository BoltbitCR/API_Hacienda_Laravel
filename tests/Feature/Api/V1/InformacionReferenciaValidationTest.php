<?php

use App\Models\Company;
use App\Models\User;

/**
 * Validación de `informacion_referencia` en POST /api/v1/documents (notas 9
 * y 10 de los Anexos 4.4). Corre antes de emitir: los 422 de acá nunca
 * llegan al generador ni a Hacienda.
 */
function tokenParaReferencias(): string
{
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner-ref', 'email' => 'ref@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1',
        'legacy_timestamp' => time(), 'last_access' => time(),
        'password' => password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);

    Company::create([
        'id' => $user->id, 'owner_user_id' => $user->id,
        'nombre' => 'ACME S.A.', 'tipo_cedula' => '02', 'cedula' => '3101234567',
        'nombre_comercial' => 'ACME', 'email' => 'facturas@acme.cr',
        'id_provincia' => '1', 'id_canton' => '01', 'id_distrito' => '01', 'id_barrio' => '01',
        'sennas' => 'Oficinas centrales', 'tel_cod_pais' => '506', 'tel_numero' => '22001100',
        'env' => 'api-stag', 'situacion' => 'normal', 'tipo_cambio' => '1',
    ]);

    return $user->createToken('test', ['master'])->plainTextToken;
}

const CLAVE_REFERENCIA = '50601102600310100000100100001010000000001100000001';

function referenciaValida(array $overrides = []): array
{
    return array_merge([
        'tipoDoc' => '01',
        'numero' => CLAVE_REFERENCIA,
        'fechaEmision' => '2026-09-30T10:00:00-06:00',
        'codigo' => '01',
        'razon' => 'Anula la factura',
    ], $overrides);
}

/** POST de un documento mínimo con las referencias dadas (o sin la clave si es null). */
function emitirConReferencias(string $tipo, ?array $referencias, bool $enParams = false, ?string $token = null)
{
    $body = [
        'tipo' => $tipo,
        'environment' => 'stag',
        'condicion_venta' => '01',
        'detalles' => [['detalle' => 'x']],
        'total_ventas' => 1,
        'total_ventas_neta' => 1,
        'total_comprobante' => 1,
    ];

    if ($referencias !== null) {
        if ($enParams) {
            $body['params'] = ['informacion_referencia' => json_encode($referencias)];
        } else {
            $body['informacion_referencia'] = $referencias;
        }
    }

    return test()->withToken($token ?? tokenParaReferencias())->postJson('/api/v1/documents', $body);
}

test('una referencia válida pasa la validación', function (string $tipo, array $ref) {
    // Pasa la validación: lo que falla después (sin credenciales) ya no es
    // un error de informacion_referencia.
    emitirConReferencias($tipo, [referenciaValida($ref)])
        ->assertJsonMissingValidationErrors(['informacion_referencia']);
})->with([
    'NC 01 sobre FE' => ['NC', []],
    'NC 13' => ['NC', ['codigo' => '13']],
    'ND 14' => ['ND', ['codigo' => '14', 'tipoDoc' => '01']],
    'NC 14 sobre FEE (19)' => ['NC', ['codigo' => '14', 'tipoDoc' => '19']],
    'FE 16 sobre rechazado (10)' => ['FE', ['codigo' => '16', 'tipoDoc' => '10']],
    'FE 15 con clave alfanumérica' => ['FE', ['codigo' => '15', 'numero' => '50601102600'.'3101A0'.'0001'.'00100001010000000001100000001']],
    'tipoDoc 99 con descripción' => ['NC', ['tipoDoc' => '99', 'tipoDocOtro' => 'Contrato', 'numero' => 'CT-1', 'codigo' => '04']],
    'código 99 con descripción' => ['ND', ['codigo' => '99', 'codigoOtro' => 'Ajuste comercial']],
    'mes vencido (13) sin número, código ni razón' => ['FE', ['tipoDoc' => '13', 'numero' => null, 'codigo' => null, 'razon' => null]],
    'tipoDoc 09 en NC' => ['NC', ['tipoDoc' => '09']],
    'tipoDoc 16 en FEC' => ['FEC', ['tipoDoc' => '16', 'numero' => 'F-001', 'codigo' => '04']],
]);

test('NC y ND exigen la referencia; FE no', function () {
    $token = tokenParaReferencias();

    emitirConReferencias('NC', null, token: $token)->assertJsonValidationErrors('informacion_referencia');
    emitirConReferencias('ND', null, token: $token)->assertJsonValidationErrors('informacion_referencia');
    emitirConReferencias('FE', null, token: $token)->assertJsonMissingValidationErrors(['informacion_referencia']);
});

test('rechaza una referencia mal armada', function (string $tipo, array $ref, string $campo) {
    emitirConReferencias($tipo, [referenciaValida($ref)])
        ->assertStatus(422)
        ->assertJsonValidationErrors($campo);
})->with([
    'tipoDoc fuera de la nota 10' => ['NC', ['tipoDoc' => '21'], 'informacion_referencia.0.tipoDoc'],
    'sin tipoDoc' => ['NC', ['tipoDoc' => null], 'informacion_referencia.0.tipoDoc'],
    'tipoDoc 99 sin descripción' => ['NC', ['tipoDoc' => '99', 'numero' => 'X-1'], 'informacion_referencia.0.tipoDocOtro'],
    'código fuera de la nota 9' => ['NC', ['codigo' => '03'], 'informacion_referencia.0.codigo'],
    'código 18' => ['NC', ['codigo' => '18'], 'informacion_referencia.0.codigo'],
    'código 99 sin descripción' => ['NC', ['codigo' => '99'], 'informacion_referencia.0.codigoOtro'],
    'código 17 fuera del REP' => ['NC', ['codigo' => '17'], 'informacion_referencia.0.codigo'],
    'sin número' => ['NC', ['numero' => null], 'informacion_referencia.0.numero'],
    'número que no es clave' => ['NC', ['numero' => '00100001010000000001'], 'informacion_referencia.0.numero'],
    'número de más de 50' => ['NC', ['tipoDoc' => '99', 'tipoDocOtro' => 'Contrato', 'numero' => str_repeat('1', 51)], 'informacion_referencia.0.numero'],
    'sin fecha' => ['NC', ['fechaEmision' => null], 'informacion_referencia.0.fechaEmision'],
    'fecha inválida' => ['NC', ['fechaEmision' => 'ayer'], 'informacion_referencia.0.fechaEmision'],
    'sin razón' => ['NC', ['razon' => null], 'informacion_referencia.0.razon'],
    'razón de más de 180' => ['NC', ['razon' => str_repeat('a', 181)], 'informacion_referencia.0.razon'],
    'tipoDoc 09 en una FE' => ['FE', ['tipoDoc' => '09'], 'informacion_referencia.0.tipoDoc'],
    'tipoDoc 16 fuera de la FEC' => ['NC', ['tipoDoc' => '16', 'numero' => 'F-001'], 'informacion_referencia.0.tipoDoc'],
]);

test('no acepta más de 10 referencias', function () {
    emitirConReferencias('NC', array_fill(0, 11, referenciaValida()))
        ->assertJsonValidationErrors('informacion_referencia');
});

test('valida igual la referencia que viene por params', function () {
    $token = tokenParaReferencias();

    emitirConReferencias('NC', [referenciaValida(['codigo' => '03'])], enParams: true, token: $token)
        ->assertJsonValidationErrors('informacion_referencia.0.codigo');

    emitirConReferencias('NC', [referenciaValida()], enParams: true, token: $token)
        ->assertJsonMissingValidationErrors(['informacion_referencia']);
});
