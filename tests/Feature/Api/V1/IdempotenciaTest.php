<?php

use App\Models\Consecutive;
use App\Models\Document;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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
    $primera = $this->withToken($token)->postJson('/api/v1/documents', $cuerpo)->assertStatus(502);
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

test('misma Idempotency-Key tras un 0 de recepción: reenvía el mismo XML y termina enviado', function () {
    [, $company, $token] = emisorDePrueba();
    $recepciones = [];
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => function ($req) use (&$recepciones) {
            // Antes de reenviar se consulta la clave: Hacienda no la conoce.
            if ($req->method() === 'GET') {
                return claveNoRecibida();
            }
            $recepciones[] = $req->data();

            return count($recepciones) === 1 ? throw new ConnectionException('timeout') : Http::response('', 202);
        },
    ]);

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-0')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502);
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-0')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($segunda->json('reenviado'))->toBeTrue()
        ->and($segunda->json('estado'))->toBe('enviado')
        ->and($segunda->json('document_id'))->toBe($primera->json('document_id'))
        ->and($segunda->json('clave'))->toBe($primera->json('clave'))
        ->and($segunda->json('xmlFirmado'))->toBe($primera->json('xmlFirmado'))
        ->and(Document::where('company_id', $company->id)->count())->toBe(1)
        ->and(Consecutive::where('company_id', $company->id)->value('numero_consecutivo'))->toBe(1);
    $this->assertDatabaseHas('documents', ['id' => $primera->json('document_id'), 'estado' => 'enviado', 'recepcion_status' => 202, 'mensaje_error' => null]);

    // Las dos recepciones llevan el mismo comprobante y la misma fecha.
    expect($recepciones)->toHaveCount(2)
        ->and($recepciones[1]['comprobanteXml'])->toBe($recepciones[0]['comprobanteXml'])
        ->and($recepciones[1]['fecha'])->toBe($recepciones[0]['fecha']);

    // Ya recibido: el siguiente intento es un replay sin tocar Hacienda.
    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-0')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(200)->assertJsonPath('idempotent_replay', true);
    expect($recepciones)->toHaveCount(2);
});

test('si el reenvío vuelve a fallar sigue en error y se puede reintentar', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa(fn ($req) => $req->method() === 'GET' ? claveNoRecibida() : Http::response('', 503));

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-503')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502);
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-503')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502);

    expect($segunda->json('reenviado'))->toBeTrue()
        ->and($segunda->json('document_id'))->toBe($primera->json('document_id'))
        ->and(Document::find($primera->json('document_id'))->estado)->toBe('error');
});

test('si falló antes de firmarse, la misma Idempotency-Key emite de nuevo con el mismo consecutivo', function () {
    [, $company, $token] = emisorDePrueba();
    $cred = $company->credentials()->first();
    $pin = $cred->pin;
    $cred->pin = 'pin-malo';
    $cred->save();
    haciendaFalsa();

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-pin')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(422);
    $fallido = Document::where('company_id', $company->id)->first();

    $cred->pin = $pin;
    $cred->save();
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-pin')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($segunda->json('estado'))->toBe('enviado')
        ->and($segunda->json('consecutivo'))->toBe($fallido->consecutivo)
        ->and($segunda->json('document_id'))->not->toBe($fallido->id)
        ->and($fallido->fresh()->idempotency_key)->toBeNull()
        ->and($fallido->fresh()->estado)->toBe('error')
        ->and(Consecutive::where('company_id', $company->id)->value('numero_consecutivo'))->toBe(1);
});

test('una emisión en curso con la misma Idempotency-Key responde 409', function () {
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();
    Document::create([
        'company_id' => $company->id, 'consecutivo' => '00100001010000000001', 'clave' => str_repeat('5', 50),
        'tipo_documento' => 'FE', 'estado' => 'generando', 'env' => 'api-stag', 'idempotency_key' => 'venta-curso',
    ]);

    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-curso')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(409);

    Cache::lock('documents:idempotency:'.$company->id.':'.hash('sha256', 'venta-lock'), 180)->get();
    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-lock')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(409);

    Http::assertNothingSent();
});

test('reenviar con el IdP caído responde 503 y no toca el documento', function () {
    [, $company, $token] = emisorDePrueba();
    $caido = false;
    Http::fake([
        '*idp*' => function () use (&$caido) {
            return $caido ? Http::failedConnection() : Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200);
        },
        '*recepcion*' => Http::response('', 500),
    ]);
    $id = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-idp')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502)->json('document_id');

    $caido = true;
    Cache::flush();
    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-idp')->postJson('/api/v1/documents', cuerpoFe())->assertStatus(503);

    expect(Document::find($id)->recepcion_status)->toBe(500);
});

test('si Hacienda ya tenía la clave, la misma Idempotency-Key no reenvía y la deja recibida', function () {
    [, $company, $token] = emisorDePrueba();
    $envios = 0;
    $consultas = [];
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => function ($req) use (&$envios, &$consultas) {
            if ($req->method() === 'GET') {
                $consultas[] = $req->url();

                return Http::response(['clave' => 'x', 'ind-estado' => 'procesando'], 200);
            }
            $envios++;

            // Hacienda lo recibe, pero la respuesta no llega.
            throw new ConnectionException('Operation timed out');
        },
    ]);

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-corte')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502);
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-corte')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($envios)->toBe(1)
        ->and($consultas)->toHaveCount(1)
        ->and($consultas[0])->toEndWith('/'.$primera->json('clave'))
        ->and($segunda->json('ya_recibido'))->toBeTrue()
        ->and($segunda->json('reenviado'))->toBeFalse()
        ->and($segunda->json('estado'))->toBe('procesando')
        ->and($segunda->json('clave'))->toBe($primera->json('clave'))
        ->and($segunda->json('xmlFirmado'))->toBe($primera->json('xmlFirmado'))
        ->and(Document::where('company_id', $company->id)->count())->toBe(1);
    $this->assertDatabaseHas('documents', ['id' => $primera->json('document_id'), 'estado' => 'procesando', 'recepcion_status' => 202, 'mensaje_error' => null]);

    // Ya recibido: replay sin tocar Hacienda.
    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-corte')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(200)->assertJsonPath('idempotent_replay', true);
    expect($envios)->toBe(1)->and($consultas)->toHaveCount(1);
});

test('si no se puede consultar a Hacienda, no reenvía y el documento no cambia', function () {
    [, , $token] = emisorDePrueba();
    $envios = 0;
    $hacienda = true;
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => function ($req) use (&$envios, &$hacienda) {
            if ($req->method() === 'GET') {
                return $hacienda ? throw new ConnectionException('Connection refused') : claveNoRecibida();
            }
            $envios++;

            return $envios === 1 ? throw new ConnectionException('timeout') : Http::response('', 202);
        },
    ]);

    $id = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-sin-consulta')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502)->json('document_id');
    $antes = Document::find($id)->only(['estado', 'recepcion_status', 'mensaje_error']);

    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-sin-consulta')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502)->assertHeader('Retry-After', '60');

    expect($envios)->toBe(1)
        ->and($segunda->json('sin_confirmar'))->toBeTrue()
        ->and($segunda->json('reenviado'))->toBeFalse()
        ->and($segunda->json('message'))->toContain('No se pudo confirmar con Hacienda')
        ->and(Document::find($id)->only(['estado', 'recepcion_status', 'mensaje_error']))->toBe($antes);

    // Cuando Hacienda contesta que no la tiene, se reenvía el mismo.
    $hacienda = false;
    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-sin-consulta')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201)->assertJsonPath('reenviado', true);
    expect($envios)->toBe(2);
});

test('si el reenvío sale repetido porque Hacienda ya lo tenía, lo deja recibido y no rechazado', function () {
    [, , $token] = emisorDePrueba();
    $envios = 0;
    $consultas = 0;
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => function ($req) use (&$envios, &$consultas) {
            if ($req->method() === 'GET') {
                // La primera consulta todavía no la muestra; después del repetido, sí.
                return ++$consultas === 1 ? claveNoRecibida() : Http::response(['clave' => 'x', 'ind-estado' => 'aceptado'], 200);
            }

            return ++$envios === 1
                ? throw new ConnectionException('Operation timed out')
                : Http::response('', 400, ['X-Error-Cause' => 'El comprobante ya fue recibido anteriormente.']);
        },
    ]);

    $id = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-repetida')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502)->json('document_id');
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-repetida')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($envios)->toBe(2)
        ->and($consultas)->toBe(2)
        ->and($segunda->json('ya_recibido'))->toBeTrue()
        ->and($segunda->json('reenviado'))->toBeTrue()
        ->and($segunda->json('estado'))->toBe('aceptado');
    $this->assertDatabaseHas('documents', ['id' => $id, 'estado' => 'aceptado', 'recepcion_status' => 202, 'mensaje_error' => null]);
});

test('si el reenvío sale rechazado y Hacienda no tiene la clave, queda rechazado', function () {
    [, , $token] = emisorDePrueba();
    $envios = 0;
    haciendaFalsa(function ($req) use (&$envios) {
        if ($req->method() === 'GET') {
            return claveNoRecibida();
        }

        return ++$envios === 1
            ? throw new ConnectionException('Operation timed out')
            : Http::response('', 400, ['X-Error-Cause' => 'El XML no es válido.']);
    });

    $id = $this->withToken($token)->withHeader('Idempotency-Key', 'venta-rechazada')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(502)->json('document_id');
    $this->withToken($token)->withHeader('Idempotency-Key', 'venta-rechazada')
        ->postJson('/api/v1/documents', cuerpoFe())->assertStatus(422)->assertJsonPath('reenviado', true);

    $this->assertDatabaseHas('documents', ['id' => $id, 'estado' => 'rechazado', 'recepcion_status' => 400]);
});
