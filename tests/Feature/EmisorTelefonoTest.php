<?php

/**
 * El teléfono del emisor se omitía siempre: el generador comparaba el número
 * (22001100) contra el largo permitido (8-20) en vez de su longitud
 * (CRLibre #215, corregido upstream en #225).
 */
test('el teléfono del emisor aparece en el XML', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $xml = base64_decode($this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201)->json('xml'));

    expect($xml)->toContain('<CodigoPais>506</CodigoPais>')
        ->toContain('<NumTelefono>22001100</NumTelefono>');
});

test('un teléfono fuera del largo 8-20 se sigue omitiendo', function () {
    [, , $token] = emisorDePrueba(['tel_numero' => '911']);
    haciendaFalsa();

    $xml = base64_decode($this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->json('xml'));

    expect($xml)->not->toContain('<NumTelefono>');
});
