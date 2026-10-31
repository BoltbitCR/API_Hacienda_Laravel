<?php

use App\Models\Consecutive;
use App\Models\Document;
use Illuminate\Support\Facades\Http;

/**
 * POST /documents/validate: genera, firma y valida contra el XSD oficial sin
 * consumir consecutivo ni enviar nada a Hacienda (P-13 / F-10).
 */
test('un comprobante correcto valida y no deja rastro', function () {
    [, $company, $token] = emisorDePrueba();
    Http::fake();

    $r = $this->withToken($token)->postJson('/api/v1/documents/validate', cuerpoFe())->assertOk();

    expect($r->json('valid'))->toBeTrue()
        ->and($r->json('errors'))->toBe([])
        ->and(base64_decode($r->json('xml')))->toContain('<FacturaElectronica')
        ->and(Document::where('company_id', $company->id)->count())->toBe(0)
        ->and(Consecutive::where('company_id', $company->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('un comprobante que no cumple el XSD devuelve los errores', function () {
    [, , $token] = emisorDePrueba();
    Http::fake();

    // CABYS de 5 dígitos: el XSD exige 13.
    $r = $this->withToken($token)->postJson('/api/v1/documents/validate', cuerpoFe([
        'detalles' => [['codigoCABYS' => '12345']],
    ]))->assertOk();

    expect($r->json('valid'))->toBeFalse()
        ->and($r->json('errors'))->not->toBe([])
        ->and(implode(' ', $r->json('errors')))->toContain('CodigoCABYS');
    Http::assertNothingSent();
});

test('la validación del cuerpo aplica igual que en la emisión', function () {
    [, , $token] = emisorDePrueba();

    $this->withToken($token)->postJson('/api/v1/documents/validate', ['tipo' => 'FE'])
        ->assertStatus(422)->assertJsonValidationErrors(['condicion_venta', 'detalles']);
});

test('con HACIENDA_XSD_BEFORE_SEND el comprobante inválido no viaja a Hacienda', function () {
    config(['hacienda.xsd_before_send' => true]);
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();

    $r = $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe([
        'detalles' => [['codigoCABYS' => '12345']],
    ]))->assertStatus(422);

    expect($r->json('message'))->toContain('XSD');
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'recepcion'));
    expect(Document::where('company_id', $company->id)->value('estado'))->toBe('error');
});
