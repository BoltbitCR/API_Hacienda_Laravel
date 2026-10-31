<?php

use App\Models\Consecutive;
use App\Models\Document;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Dos peticiones iguales no producen dos comprobantes: Idempotency-Key y
 * consecutivo único por empresa.
 */
test('la misma Idempotency-Key devuelve el comprobante ya emitido sin emitir otro', function () {
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-77')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-77')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(200);

    expect($segunda->json('idempotent_replay'))->toBeTrue()
        ->and($segunda->json('document_id'))->toBe($primera->json('document_id'))
        ->and($segunda->json('clave'))->toBe($primera->json('clave'))
        ->and($segunda->json('xmlFirmado'))->toBe($primera->json('xmlFirmado'))
        ->and(Document::where('company_id', $company->id)->count())->toBe(1)
        ->and(Consecutive::where('company_id', $company->id)->value('numero_consecutivo'))->toBe(1);

    Http::assertSentCount(2); // IdP + una sola recepción
});

test('reenviar el mismo consecutivo del cliente tras un 202 responde 409 y no llega a Hacienda', function () {
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();

    $cuerpo = cuerpoFe(['params' => ['consecutivo' => '0000000045', 'codigoSeguridad' => '11111111']]);
    $primera = $this->withToken($token)->postJson('/api/v1/documents', $cuerpo)->assertStatus(201);

    $segunda = $this->withToken($token)->postJson('/api/v1/documents', $cuerpo)->assertStatus(409);

    expect($segunda->json('document_id'))->toBe($primera->json('document_id'))
        ->and($segunda->json('message'))->toContain($primera->json('consecutivo'))
        ->and(Document::where('company_id', $company->id)->count())->toBe(1);
    Http::assertSentCount(2);
});

test('si Hacienda no recibió el intento anterior, la misma clave se puede reintentar', function () {
    [, $company, $token] = emisorDePrueba();
    $intentos = 0;
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        // La primera recepción falla por red; la segunda responde 202.
        '*recepcion*' => function () use (&$intentos) {
            return ++$intentos === 1 ? throw new ConnectionException('timeout') : Http::response('', 202);
        },
    ]);
    $cuerpo = cuerpoFe(['params' => ['consecutivo' => '0000000046', 'codigoSeguridad' => '22222222']]);
    $primera = $this->withToken($token)->postJson('/api/v1/documents', $cuerpo)->assertStatus(201);
    expect($primera->json('estado'))->toBe('error');

    $segunda = $this->withToken($token)->postJson('/api/v1/documents', $cuerpo)->assertStatus(201);

    expect($segunda->json('estado'))->toBe('enviado')
        ->and($segunda->json('clave'))->toBe($primera->json('clave'))
        ->and(Document::where('company_id', $company->id)->count())->toBe(2);
});

test('la Idempotency-Key es por empresa', function () {
    [, , $tokenA] = emisorDePrueba();
    [, , $tokenB] = emisorDePrueba(['cedula' => '3101999888']);
    haciendaFalsa();

    $this->withToken($tokenA)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);
    app('auth')->forgetGuards(); // otro principal en la misma app de pruebas
    $this->withToken($tokenB)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect(Document::count())->toBe(2);
});
