<?php

use App\Models\Document;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * El estado del comprobante refleja la respuesta de la recepción de Hacienda
 * (antes todo quedaba `enviado` con 201) y la consulta guarda el
 * MensajeHacienda (#25).
 */
test('recepción 202: queda enviado y la respuesta lo dice', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($r->json('estado'))->toBe('enviado')
        ->and($r->json('recepcion.status'))->toBe(202)
        ->and($r->json('recepcion.causa'))->toBeNull();
    $this->assertDatabaseHas('documents', ['id' => $r->json('document_id'), 'estado' => 'enviado', 'recepcion_status' => 202]);
});

test('recepción 400: queda rechazado con la causa de X-Error-Cause', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa(Http::response('', 400, ['X-Error-Cause' => 'Comprobante con clave duplicada']));

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($r->json('estado'))->toBe('rechazado')
        ->and($r->json('recepcion.status'))->toBe(400)
        ->and($r->json('recepcion.causa'))->toBe('Comprobante con clave duplicada')
        ->and($r->json('envio.Status'))->toBe(400); // el campo histórico sigue
    $this->assertDatabaseHas('documents', [
        'id' => $r->json('document_id'), 'estado' => 'rechazado', 'recepcion_status' => 400,
        'mensaje_error' => 'Comprobante con clave duplicada',
    ]);
});

test('sin conexión con Hacienda: queda en error, no en enviado', function () {
    [, , $token] = emisorDePrueba();
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => fn () => throw new ConnectionException('cURL error 28: timeout'),
    ]);

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    expect($r->json('estado'))->toBe('error')
        ->and($r->json('recepcion.status'))->toBe(0)
        ->and($r->json('recepcion.causa'))->toContain('timeout');
    $this->assertDatabaseHas('documents', ['id' => $r->json('document_id'), 'estado' => 'error', 'recepcion_status' => 0]);
});

test('la consulta de estado guarda el MensajeHacienda y el detalle lo expone', function () {
    [, , $token] = emisorDePrueba();
    $respuesta = base64_encode('<MensajeHacienda><Mensaje>1</Mensaje></MensajeHacienda>');
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        // POST = recepción (202); GET /recepcion/{clave} = consulta de estado.
        '*recepcion*' => fn ($req) => $req->method() === 'GET'
            ? Http::response(['clave' => 'x', 'ind-estado' => 'aceptado', 'respuesta-xml' => $respuesta], 200)
            : Http::response('', 202),
    ]);
    $id = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->json('document_id');

    $this->withToken($token)->getJson("/api/v1/documents/$id/status")
        ->assertOk()
        ->assertJsonPath('document.estado', 'aceptado')
        ->assertJsonPath('document.respuesta_mh_base64', $respuesta);

    expect(Document::find($id)->respuesta_mh_base64)->toBe($respuesta);

    $this->withToken($token)->getJson("/api/v1/documents/$id")
        ->assertOk()
        ->assertJsonPath('data.respuesta_mh_base64', $respuesta)
        ->assertJsonStructure(['data' => ['xml_enviado_base64', 'recepcion_status', 'mensaje_error']]);
});

test('un fallo antes de enviar deja el intento registrado en error y no toca Hacienda', function () {
    [, $company, $token] = emisorDePrueba();
    $cred = $company->credentials()->first();
    $cred->pin = 'pin-malo';
    $cred->save();
    haciendaFalsa();

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(422);

    $doc = Document::where('company_id', $company->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->estado)->toBe('error')
        ->and($doc->mensaje_error)->toContain('firmando');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'recepcion'));
});
