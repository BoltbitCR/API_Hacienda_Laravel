<?php

use App\Models\Consecutive;
use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * IdP de Hacienda caído = 503 con Retry-After (repetir sirve); credenciales
 * rechazadas = 422 (repetir no sirve). Ninguno de los dos consume consecutivo.
 */
dataset('idp caído', [
    'timeout' => fn () => Http::failedConnection('cURL error 28: Operation timed out'),
    '500' => fn () => Http::response('<html>Internal Server Error</html>', 500),
    '503' => fn () => Http::response('', 503),
]);

test('emitir con el IdP caído responde 503 sin consumir consecutivo', function (Closure $idp) {
    [, $company, $token] = emisorDePrueba();
    Http::fake(['*idp*' => $idp(), '*recepcion*' => Http::response('', 202)]);

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'IdP de Hacienda'));

    expect(Document::where('company_id', $company->id)->count())->toBe(0)
        ->and(Consecutive::where('company_id', $company->id)->count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'recepcion'));
})->with('idp caído');

test('emitir con credenciales rechazadas por el IdP sigue en 422', function () {
    [, $company, $token] = emisorDePrueba();
    Http::fake(['*idp*' => Http::response(['error' => 'invalid_grant'], 401)]);

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())
        ->assertStatus(422)
        ->assertJsonPath('message', 'No se pudo obtener el token de Hacienda.');

    expect(Consecutive::where('company_id', $company->id)->count())->toBe(0);
});

/**
 * Emite un comprobante con el IdP sano y deja el IdP respondiendo $despues
 * (con la caché de tokens vacía) para lo que sigue.
 */
function emitidoConIdpQueLuego(Closure $despues, string $token): int
{
    $sano = true;
    Http::fake([
        '*idp*' => function () use (&$sano, $despues) {
            return $sano ? Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200) : $despues();
        },
        '*recepcion*' => Http::response('', 202),
    ]);

    $id = test()->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertCreated()->json('document_id');
    $sano = false;
    Cache::flush();

    return $id;
}

test('consultar el estado con el IdP caído responde 503 y no toca el documento', function (Closure $idp) {
    [, , $token] = emisorDePrueba();
    $id = emitidoConIdpQueLuego($idp, $token);

    $this->withToken($token)->getJson("/api/v1/documents/$id/status")
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60');

    expect(Document::find($id)->estado)->toBe('enviado');
})->with('idp caído');

test('consultar el estado con credenciales rechazadas responde 422 sin consultar Hacienda', function () {
    [, , $token] = emisorDePrueba();
    $id = emitidoConIdpQueLuego(fn () => Http::response(['error' => 'invalid_grant'], 401), $token);

    $this->withToken($token)->getJson("/api/v1/documents/$id/status")->assertStatus(422);

    Http::assertNotSent(fn ($req) => $req->method() === 'GET' && str_contains($req->url(), 'recepcion'));
});

test('consulta por clave con el IdP caído responde 503', function () {
    [, , $token] = emisorDePrueba();
    Http::fake(['*idp*' => Http::response('', 502)]);

    $this->withToken($token)->getJson('/api/v1/hacienda/status?clave='.str_repeat('5', 50))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60');
});
