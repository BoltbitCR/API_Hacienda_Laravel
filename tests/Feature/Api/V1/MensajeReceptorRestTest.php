<?php

use App\Models\Consecutive;
use App\Models\Document;
use App\Services\Xsd\XsdValidatorService;
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
