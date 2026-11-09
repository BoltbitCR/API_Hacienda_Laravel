<?php

use App\Models\Consecutive;
use App\Models\Document;
use App\Services\Xsd\XsdValidatorService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mensaje receptor por POST /api/v1/documents (#24): lleva la clave del
 * comprobante recibido, el consecutivo propio del tipo 05/06/07 y el
 * payload de recepción de sendMensaje.
 */
const CLAVE_RECIBIDA = '50601102600310199988800100001010000000001100000001';

function cuerpoMr(array $overrides = []): array
{
    return array_replace([
        'tipo' => 'MR',
        'environment' => 'stag',
        'clave' => CLAVE_RECIBIDA,
        'mensaje' => '1',
        'tipo_identificacion_emisor' => '02',
        'numero_cedula_emisor' => '3101999888',
        'fecha_emision' => '2026-10-30T09:00:00-06:00',
        'total_factura' => 1130.00,
        'monto_total_impuesto' => 130.00,
        'codigo_actividad' => '620100',
        'condicion_impuesto' => '01',
    ], $overrides);
}

test('acepta un comprobante recibido: clave ajena, consecutivo 05 y payload de sendMensaje', function () {
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr())->assertStatus(201);

    expect($r->json('clave'))->toBe(CLAVE_RECIBIDA)
        ->and(substr($r->json('consecutivo'), 8, 2))->toBe('05')
        ->and($r->json('estado'))->toBe('enviado');

    $xml = base64_decode($r->json('xmlFirmado'));
    expect($xml)->toContain('<Clave>'.CLAVE_RECIBIDA.'</Clave>')
        ->toContain('<NumeroCedulaEmisor>003101999888</NumeroCedulaEmisor>')
        ->toContain('<NumeroCedulaReceptor>003101234567</NumeroCedulaReceptor>')
        ->toContain('<CondicionImpuesto>01</CondicionImpuesto>')
        ->toContain('<NumeroConsecutivoReceptor>'.$r->json('consecutivo').'</NumeroConsecutivoReceptor>');

    expect(app(XsdValidatorService::class)->validate($xml, 'CCE')['errors'])->toBe([]);

    Http::assertSent(function ($req) use ($r) {
        if (! str_contains($req->url(), 'recepcion')) {
            return false;
        }
        $body = json_decode($req->body(), true);

        return $body['clave'] === CLAVE_RECIBIDA
            && $body['emisor'] === ['tipoIdentificacion' => '02', 'numeroIdentificacion' => '3101999888']
            && $body['receptor'] === ['tipoIdentificacion' => '02', 'numeroIdentificacion' => '3101234567']
            && $body['consecutivoReceptor'] === $r->json('consecutivo');
    });

    expect(Document::where('company_id', $company->id)->value('clave'))->toBe(CLAVE_RECIBIDA)
        ->and(Consecutive::where('company_id', $company->id)->value('tipo_comprobante'))->toBe('CCE');
});

test('el rechazo lleva tipo 07, exige detalle y lo escribe en el XML', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr(['mensaje' => '3']))
        ->assertStatus(422)->assertJsonValidationErrors('detalle_mensaje');

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr([
        'mensaje' => '3', 'detalle_mensaje' => 'Monto no corresponde',
    ]))->assertStatus(201);

    expect(substr($r->json('consecutivo'), 8, 2))->toBe('07')
        ->and(base64_decode($r->json('xmlFirmado')))->toContain('<Mensaje>3</Mensaje>')
        ->toContain('<DetalleMensaje>Monto no corresponde</DetalleMensaje>');
});

test('la aceptación parcial lleva tipo 06 y su propia serie', function () {
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr())->assertStatus(201);
    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr([
        'mensaje' => '2', 'detalle_mensaje' => 'Se acepta parcialmente',
        'clave' => '50601102600310199988800100001010000000002100000002',
    ]))->assertStatus(201);

    expect(substr($r->json('consecutivo'), 8, 2))->toBe('06')
        ->and(substr($r->json('consecutivo'), -10))->toBe('0000000001')
        ->and(Consecutive::where('company_id', $company->id)->pluck('tipo_comprobante')->sort()->values()->all())->toBe(['CCE', 'CPCE']);
});

test('sin clave, mensaje o emisor del comprobante recibido responde 422', function () {
    [, , $token] = emisorDePrueba();

    $this->withToken($token)->postJson('/api/v1/documents', ['tipo' => 'MR', 'environment' => 'stag'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clave', 'mensaje', 'tipo_identificacion_emisor', 'numero_cedula_emisor', 'total_factura']);
});

/**
 * Hacienda falsa para el estado de un mensaje receptor: la clave sola es la factura del
 * proveedor (aceptada, la tiene Hacienda) y `{clave}-{consecutivo}` es el mensaje, que
 * responde $mensaje. Así lo distingue el sandbox (2026-11): cada una trae su MensajeHacienda.
 * Guarda en $consultas las claves consultadas y en $envios los POST a la recepción.
 */
function haciendaConFacturaDelProveedor(Closure $mensaje, array &$consultas, array &$envios, ?Closure $recepcion = null): void
{
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => function ($req) use ($mensaje, &$consultas, &$envios, $recepcion) {
            if ($req->method() === 'GET') {
                $consulta = substr($req->url(), strrpos($req->url(), '/') + 1);
                $consultas[] = $consulta;

                return $consulta === CLAVE_RECIBIDA
                    ? Http::response(['clave' => CLAVE_RECIBIDA, 'ind-estado' => 'aceptado', 'respuesta-xml' => base64_encode('<MensajeHacienda>factura</MensajeHacienda>')], 200)
                    : $mensaje($consulta);
            }
            $envios[] = $req->data();

            return $recepcion ? $recepcion(count($envios)) : Http::response('', 202);
        },
    ]);
}

test('reenviar un MR tras un 5xx consulta {clave}-{consecutivo} y lo reenvía si Hacienda no tiene el mensaje', function () {
    [, $company, $token] = emisorDePrueba();
    $consultas = [];
    $envios = [];
    haciendaConFacturaDelProveedor(
        fn (string $consulta) => Http::response('', 400, ['X-Error-Cause' => "El comprobante [$consulta] no ha sido recibido."]),
        $consultas,
        $envios,
        fn (int $n) => $n === 1 ? Http::response('<html>Bad Gateway</html>', 502) : Http::response('', 202),
    );

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'mr-5xx')
        ->postJson('/api/v1/documents', cuerpoMr())->assertStatus(502);
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'mr-5xx')
        ->postJson('/api/v1/documents', cuerpoMr())->assertStatus(201);

    $consecutivo = $primera->json('consecutivo');
    expect($consultas)->toBe([CLAVE_RECIBIDA.'-'.$consecutivo])
        ->and($envios)->toHaveCount(2)
        ->and($envios[1]['comprobanteXml'])->toBe($envios[0]['comprobanteXml'])
        ->and($envios[1]['consecutivoReceptor'])->toBe($consecutivo)
        ->and($segunda->json('reenviado'))->toBeTrue()
        ->and($segunda->json('ya_recibido'))->toBeNull()
        ->and($segunda->json('estado'))->toBe('enviado');
    $this->assertDatabaseHas('documents', [
        'id' => $primera->json('document_id'), 'company_id' => $company->id, 'estado' => 'enviado',
        'recepcion_status' => 202, 'respuesta_mh_base64' => null,
    ]);
});

test('reenviar un MR que Hacienda ya tiene lo deja recibido con el estado del mensaje, no el de la factura', function () {
    [, , $token] = emisorDePrueba();
    $consultas = [];
    $envios = [];
    $respuestaMensaje = base64_encode('<MensajeHacienda>mensaje receptor</MensajeHacienda>');
    haciendaConFacturaDelProveedor(
        fn (string $consulta) => Http::response(['clave' => $consulta, 'ind-estado' => 'procesando', 'respuesta-xml' => $respuestaMensaje], 200),
        $consultas,
        $envios,
        fn () => throw new ConnectionException('Operation timed out'),
    );

    $primera = $this->withToken($token)->withHeader('Idempotency-Key', 'mr-corte')
        ->postJson('/api/v1/documents', cuerpoMr())->assertStatus(502);
    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', 'mr-corte')
        ->postJson('/api/v1/documents', cuerpoMr())->assertStatus(201);

    expect($consultas)->toBe([CLAVE_RECIBIDA.'-'.$primera->json('consecutivo')])
        ->and($envios)->toHaveCount(1)
        ->and($segunda->json('ya_recibido'))->toBeTrue()
        ->and($segunda->json('reenviado'))->toBeFalse()
        ->and($segunda->json('estado'))->toBe('procesando');
    $this->assertDatabaseHas('documents', [
        'id' => $primera->json('document_id'), 'estado' => 'procesando', 'recepcion_status' => 202,
        'respuesta_mh_base64' => $respuestaMensaje,
    ]);
});

test('el estado de un MR consulta {clave}-{consecutivo} y no toma el de la factura del proveedor', function () {
    [, , $token] = emisorDePrueba();
    $consultas = [];
    $envios = [];
    $respuestaMensaje = base64_encode('<MensajeHacienda>mensaje receptor</MensajeHacienda>');
    haciendaConFacturaDelProveedor(
        fn (string $consulta) => Http::response(['clave' => $consulta, 'ind-estado' => 'rechazado', 'respuesta-xml' => $respuestaMensaje], 200),
        $consultas,
        $envios,
    );

    $mr = $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr())->assertCreated();
    $r = $this->withToken($token)->getJson('/api/v1/documents/'.$mr->json('document_id').'/status')->assertOk();

    $claveMensaje = CLAVE_RECIBIDA.'-'.$mr->json('consecutivo');
    expect($consultas)->toBe([$claveMensaje])
        ->and($r->json('hacienda.clave'))->toBe($claveMensaje)
        ->and($r->json('hacienda.ind-estado'))->toBe('rechazado');
    $this->assertDatabaseHas('documents', ['id' => $mr->json('document_id'), 'estado' => 'rechazado', 'respuesta_mh_base64' => $respuestaMensaje]);
});

test('el estado de un MR que Hacienda no recibió responde 404 aunque tenga la factura del proveedor', function () {
    [, , $token] = emisorDePrueba();
    $consultas = [];
    $envios = [];
    haciendaConFacturaDelProveedor(
        fn (string $consulta) => Http::response('', 400, ['X-Error-Cause' => "El comprobante [$consulta] no ha sido recibido."]),
        $consultas,
        $envios,
    );

    $mr = $this->withToken($token)->postJson('/api/v1/documents', cuerpoMr())->assertCreated();
    $this->withToken($token)->getJson('/api/v1/documents/'.$mr->json('document_id').'/status')->assertNotFound();

    expect($consultas)->toBe([CLAVE_RECIBIDA.'-'.$mr->json('consecutivo')])
        ->and(Document::find($mr->json('document_id'))->estado)->toBe('enviado');
});

test('una FE se sigue consultando por su clave sola', function () {
    [, , $token] = emisorDePrueba();
    $consultas = [];
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300], 200),
        '*recepcion*' => function ($req) use (&$consultas) {
            if ($req->method() === 'GET') {
                $consultas[] = substr($req->url(), strrpos($req->url(), '/') + 1);

                return Http::response(['ind-estado' => 'aceptado'], 200);
            }

            return Http::response('', 202);
        },
    ]);

    $fe = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertCreated();
    $this->withToken($token)->getJson('/api/v1/documents/'.$fe->json('document_id').'/status')->assertOk();

    expect($consultas)->toBe([$fe->json('clave')]);
});
