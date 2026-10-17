<?php

use Tests\Parity\GoldenFixture;

/**
 * El MensajeReceptor rellena con ceros las cédulas numéricas (paridad con
 * el legacy). Una jurídica alfanumérica va tal cual: el XSD 4.4 vigente ya
 * admite letras en NumeroCedulaEmisor (maxLength 20, sin patrón).
 */
function mrConCedulas(string $emisor, string $receptor): string
{
    $params = array_merge(GoldenFixture::all()['genxml-mr-aceptado']->params, [
        'numero_cedula_emisor' => $emisor,
        'numero_cedula_receptor' => $receptor,
    ]);

    $body = json_decode(test()->call('POST', '/api.php', $params)->getContent(), true);

    return base64_decode($body['resp']['xml']);
}

test('una cédula alfanumérica no se rellena con ceros', function () {
    $xml = mrConCedulas('3101A00001', '3101B00002');

    expect($xml)->toContain('<NumeroCedulaEmisor>3101A00001</NumeroCedulaEmisor>')
        ->toContain('<NumeroCedulaReceptor>3101B00002</NumeroCedulaReceptor>');
});

test('las cédulas numéricas se siguen rellenando a 12', function () {
    $xml = mrConCedulas('3101234567', '206540123');

    expect($xml)->toContain('<NumeroCedulaEmisor>003101234567</NumeroCedulaEmisor>')
        ->toContain('<NumeroCedulaReceptor>000206540123</NumeroCedulaReceptor>');
});
