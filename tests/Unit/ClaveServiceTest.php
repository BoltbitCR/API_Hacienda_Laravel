<?php

use App\Services\Clave\ClaveService;

/**
 * Clave de 50 posiciones y consecutivo de 20 (Anexos 4.4, notas 3 y 4.1).
 * La cédula jurídica puede traer el consecutivo alfanumérico del Registro
 * Nacional (oficio DGL-188-2026): 3101A00001.
 */
function clave(string $tipoCedula, string $cedula, string $tipo = 'FE'): array|string
{
    return (new ClaveService)->generate(
        tipoDocumento: $tipo,
        tipoCedula: $tipoCedula,
        cedula: $cedula,
        situacion: 'normal',
        codigoPais: '506',
        consecutivo: '1',
        codigoSeguridad: '12345678',
        fecha: new DateTimeImmutable('2026-11-01 10:00:00'),
    );
}

test('una cédula jurídica numérica produce la misma clave de siempre', function () {
    expect(clave('02', '3101234567'))->toBe([
        'clave' => '506011126'.'003101234567'.'00100001010000000001'.'1'.'12345678',
        'consecutivo' => '00100001010000000001',
        'length' => 50,
    ]);
});

test('las demás cédulas numéricas no cambian', function (string $tipo, string $cedula, string $identificacion) {
    expect(clave($tipo, $cedula)['clave'])->toBe('506011126'.$identificacion.'00100001010000000001112345678');
})->with([
    'física' => ['01', '208830333', '000208830333'],
    'física (alias)' => ['fisico', '208830333', '000208830333'],
    'DIMEX de 11' => ['03', '18620012345', '018620012345'],
    'DIMEX de 12' => ['03', '186200123456', '186200123456'],
    'NITE' => ['04', '3002123456', '003002123456'],
]);

test('una cédula jurídica alfanumérica se rellena con dos ceros', function (string $cedula) {
    $r = clave('02', $cedula);

    expect($r['clave'])->toBe('506011126'.'003101A00001'.'00100001010000000001'.'1'.'12345678')
        ->and($r['length'])->toBe(50)
        ->and($r['clave'])->toMatch('/^[a-zA-Z0-9]{50}$/');
})->with([
    'normalizada' => ['3101A00001'],
    'con guiones' => ['3-101-A00001'],
    'en minúsculas' => ['3-101-a00001'],
]);

test('el alias juridico acepta la cédula alfanumérica', function () {
    expect(clave('juridico', '3101A00001')['clave'])->toContain('003101A00001');
});

test('el consecutivo sigue siendo numérico con una cédula alfanumérica', function () {
    expect(clave('02', '3101A00001', 'NC')['consecutivo'])->toBe('00100001030000000001');
});

test('rechaza cédulas alfanuméricas inválidas', function (string $tipo, string $cedula) {
    expect(clave($tipo, $cedula))->toBe('El parametro cedula no es numeral');
})->with([
    'física con letras' => ['01', '2088A0333'],
    'DIMEX con letras' => ['03', '1862001234A'],
    'NITE con letras' => ['04', '300212345A'],
    'jurídica con letra en la clase' => ['02', '310AA00001'],
    'jurídica corta' => ['02', '3101A0001'],
    'jurídica con símbolos' => ['02', '3101A0000*'],
]);

test('una jurídica numérica de más de 12 sigue siendo un error', function () {
    expect(clave('02', '3101234567890'))->toBe('cedula juridico incorrecto');
});

test('normalizarCedula', function () {
    expect(ClaveService::normalizarCedula('3-101-a00001'))->toBe('3101A00001')
        ->and(ClaveService::normalizarCedula(' 3 101 123456 '))->toBe('3101123456');
});
