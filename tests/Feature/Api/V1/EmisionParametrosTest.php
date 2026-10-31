<?php

use Illuminate\Support\Facades\Http;

/**
 * Parámetros de la clave y del envío: fecha de la clave = FechaEmision,
 * código de seguridad aleatorio, situación por comprobante, callbackUrl y
 * ProveedorSistemas configurable.
 */
test('la fecha de la clave es la de fecha_emision, no la del servidor', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $clave = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe([
        'fecha_emision' => '2026-03-09T23:30:00-06:00',
    ]))->assertStatus(201)->json('clave');

    // Posiciones 4-9: ddmmyy.
    expect(substr($clave, 3, 6))->toBe('090326');
});

test('sin fecha_emision el XML lleva la hora actual de Costa Rica y la clave el mismo día', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();
    $this->travelTo(now()->setTimezone('America/Costa_Rica')->setDate(2026, 11, 2)->setTime(8, 0));

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe(['fecha_emision' => null]))->assertStatus(201);

    expect(substr($r->json('clave'), 3, 6))->toBe('021126')
        ->and(base64_decode($r->json('xml')))->toContain('<FechaEmision>2026-11-02T08:00:00-06:00</FechaEmision>');
});

test('el código de seguridad es aleatorio cuando el cliente no lo manda', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $a = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->json('clave');
    $b = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->json('clave');

    expect(substr($a, -8))->toMatch('/^\d{8}$/')->not->toBe('00000000')
        ->and(substr($a, -8))->not->toBe(substr($b, -8));
});

test('la situación va por comprobante: sininternet pone 3 en la posición 42', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $clave = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe(['situacion' => 'sininternet']))
        ->assertStatus(201)->json('clave');

    expect($clave[41])->toBe('3');

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe(['situacion' => 'otra']))
        ->assertStatus(422)->assertJsonValidationErrors('situacion');
});

test('callback_url viaja a Hacienda como callbackUrl', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe(['callback_url' => 'https://mi-sistema.example/webhooks/hacienda']))
        ->assertStatus(201);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'recepcion')
        && json_decode($req->body(), true)['callbackUrl'] === 'https://mi-sistema.example/webhooks/hacienda');

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe(['callback_url' => 'no-es-url']))
        ->assertStatus(422)->assertJsonValidationErrors('callback_url');
});

test('ProveedorSistemas sale de la empresa cuando está configurado', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $this->withToken($token)->putJson('/api/v1/company', ['proveedor_sistemas' => '1-1234-0567'])
        ->assertOk()->assertJsonPath('proveedor_sistemas', '1-1234-0567');

    $xml = base64_decode($this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201)->json('xml'));
    expect($xml)->toContain('<ProveedorSistemas>112340567</ProveedorSistemas>')
        ->toContain('<Numero>3101234567</Numero>');

    // Sin proveedor configurado, la del emisor (desarrollo propio).
    $this->withToken($token)->putJson('/api/v1/company', ['proveedor_sistemas' => null])->assertOk();
    $xml = base64_decode($this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->json('xml'));
    expect($xml)->toContain('<ProveedorSistemas>3101234567</ProveedorSistemas>');
});
