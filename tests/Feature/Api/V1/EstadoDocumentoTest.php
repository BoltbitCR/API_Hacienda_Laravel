<?php

use App\Models\Document;
use Illuminate\Support\Facades\Http;

/**
 * GET /documents/{id}/status distingue «Hacienda no lo tiene» (404) de «no
 * se pudo consultar» (502) y no toca el estado guardado en ninguno de los dos.
 *
 * @param  Closure  $consulta  respuesta de Hacienda al GET /recepcion/{clave}
 */
function emitidoYConsultado(Closure $consulta, string $token): array
{
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => fn ($req) => $req->method() === 'GET' ? $consulta() : Http::response('', 202),
    ]);

    $id = test()->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertCreated()->json('document_id');

    return [$id, test()->withToken($token)->getJson("/api/v1/documents/$id/status")];
}

test('Hacienda acepta: 200 y el estado se actualiza', function () {
    [, , $token] = emisorDePrueba();
    [$id, $r] = emitidoYConsultado(fn () => Http::response(['ind-estado' => 'aceptado'], 200), $token);

    $r->assertOk()->assertJsonPath('hacienda.ind-estado', 'aceptado');
    expect(Document::find($id)->estado)->toBe('aceptado');
});

dataset('hacienda no tiene la clave', [
    '400 no ha sido recibido' => fn () => claveNoRecibida(),
    '400 no ha sido recibido en mayúsculas' => fn () => Http::response('', 400, ['X-Error-Cause' => 'EL COMPROBANTE [X] NO HA SIDO RECIBIDO.']),
    '404' => fn () => Http::response('', 404),
]);

test('Hacienda no tiene la clave: 404 y el estado no cambia', function (Closure $consulta) {
    [, , $token] = emisorDePrueba();
    [$id, $r] = emitidoYConsultado($consulta, $token);

    $r->assertNotFound()->assertJsonPath('message', 'Hacienda no tiene información para esa clave.');
    expect(Document::find($id)->estado)->toBe('enviado');
})->with('hacienda no tiene la clave');

dataset('no se pudo consultar', [
    'timeout' => [fn () => Http::failedConnection('cURL error 28: Operation timed out'), 'No se pudo consultar Hacienda'],
    '500' => [fn () => Http::response('<html>Error</html>', 500), 'Hacienda respondió 500'],
    '401' => [fn () => Http::response('', 401, ['X-Error-Cause' => 'Token expirado']), 'Hacienda respondió 401 a la consulta: Token expirado'],
    '400 sin causa' => [fn () => Http::response('', 400), 'Hacienda respondió 400 a la consulta.'],
    '400 con otra causa' => [fn () => Http::response('', 400, ['X-Error-Cause' => 'La clave no es válida']), 'Hacienda respondió 400 a la consulta: La clave no es válida'],
    '200 vacío' => [fn () => Http::response('', 200), 'Hacienda respondió 200 a la consulta sin el estado del comprobante'],
]);

test('no se pudo consultar Hacienda: 502 con la causa y el estado no cambia', function (Closure $consulta, string $mensaje) {
    [, , $token] = emisorDePrueba();
    [$id, $r] = emitidoYConsultado($consulta, $token);

    $r->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, $mensaje));
    expect(Document::find($id)->estado)->toBe('enviado');
})->with('no se pudo consultar');
