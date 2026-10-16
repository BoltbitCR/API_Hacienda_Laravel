<?php

use Tests\Parity\GoldenFixture;

/**
 * InformacionReferencia con los catálogos de la revisión de Anexos 4.4
 * (nota 9: códigos 13-17; nota 10: TipoDocIR 19 y 20).
 *
 * Reusa los parámetros de los golden genxml-*-full y cambia solo
 * informacion_referencia, así que el resto del XML es el ya probado.
 */
const CLAVE_REF = '50601102600310100000100100001010000000001100000001';

/** @return array{status: int, body: array<mixed>, xml: ?string} */
function genXmlConReferencia($test, string $golden, array $referencias): array
{
    $fixture = GoldenFixture::all()[$golden];
    $params = array_merge($fixture->params, [
        'informacion_referencia' => json_encode($referencias),
    ]);

    $response = $test->call('POST', '/api.php', $params);
    $body = json_decode($response->getContent(), true) ?? [];
    $xml = isset($body['resp']['xml']) ? base64_decode($body['resp']['xml']) : null;

    return ['status' => $response->getStatusCode(), 'body' => $body, 'xml' => $xml];
}

function referencia(array $overrides = []): array
{
    return array_merge([
        'tipoDoc' => '01',
        'numero' => CLAVE_REF,
        'fechaEmision' => '2026-09-30T10:00:00-06:00',
        'codigo' => '01',
        'razon' => 'Corrección',
    ], $overrides);
}

/** Nodos de la primera InformacionReferencia del XML. */
function nodosReferencia(string $xml): array
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue('XML mal formado');

    $nodos = [];
    foreach ($dom->getElementsByTagName('InformacionReferencia')->item(0)->childNodes as $n) {
        if ($n instanceof DOMElement) {
            $nodos[$n->nodeName] = $n->textContent;
        }
    }

    return $nodos;
}

test('genera la referencia con los códigos nuevos', function (string $golden, array $ref) {
    $r = genXmlConReferencia($this, $golden, [referencia($ref)]);

    expect($r['xml'])->not->toBeNull(json_encode($r['body']));
    expect(nodosReferencia($r['xml']))->toMatchArray([
        'TipoDocIR' => $ref['tipoDoc'] ?? '01',
        'Codigo' => $ref['codigo'],
    ]);
})->with([
    'NC que anula por error material (13)' => ['genxml-nc-full', ['codigo' => '13']],
    'NC que corrige monto de una FEE (14, TipoDocIR 19)' => ['genxml-nc-full', ['codigo' => '14', 'tipoDoc' => '19']],
    'ND que corrige monto por error material (14)' => ['genxml-nd-full', ['codigo' => '14']],
    'FE que sustituye una rechazada (16, TipoDocIR 10)' => ['genxml-fe-full', ['codigo' => '16', 'tipoDoc' => '10']],
    'FE que sustituye por error material (15)' => ['genxml-fe-full', ['codigo' => '15']],
    'TE con TipoDocIR 20' => ['genxml-te-con-receptor', ['codigo' => '04', 'tipoDoc' => '20']],
    'FEC con código 13' => ['genxml-fec', ['codigo' => '13']],
    'FEE con código 16' => ['genxml-fee', ['codigo' => '16', 'tipoDoc' => '10']],
]);

test('la clave alfanumérica pasa como numero', function () {
    $clave = '50601102600'.'3101A0'.'0001'.'00100001010000000001100000001';
    expect(strlen($clave))->toBe(50);

    $r = genXmlConReferencia($this, 'genxml-nc-full', [referencia(['numero' => $clave, 'codigo' => '13'])]);

    expect(nodosReferencia($r['xml'])['Numero'])->toBe($clave);
});

test('rechaza un código fuera del catálogo', function (string $codigo, string $mensaje) {
    $r = genXmlConReferencia($this, 'genxml-nc-full', [referencia(['codigo' => $codigo])]);

    expect($r['xml'])->toBeNull()
        ->and(json_encode($r['body'], JSON_UNESCAPED_UNICODE))->toContain($mensaje);
})->with([
    'no existe (03)' => ['03', 'codigo = 03'],
    'no existe (18)' => ['18', 'codigo = 18'],
    'solo REP (17)' => ['17', 'Recibo Electrónico de Pago'],
]);

test('rechaza un numero que no es clave cuando el TipoDocIR lo exige', function () {
    $r = genXmlConReferencia($this, 'genxml-nc-full', [referencia(['numero' => '00100001010000000001'])]);

    expect($r['xml'])->toBeNull()
        ->and(json_encode($r['body'], JSON_UNESCAPED_UNICODE))->toContain('clave de 50 caracteres');
});

test('con TipoDocIR 99 el numero puede ser cualquier cosa', function () {
    $r = genXmlConReferencia($this, 'genxml-nc-full', [
        referencia(['tipoDoc' => '99', 'tipoDocOtro' => 'Contrato', 'numero' => 'CT-2026-01', 'codigo' => '04']),
    ]);

    expect(nodosReferencia($r['xml'])['Numero'])->toBe('CT-2026-01');
});

test('escapa razon y numero', function () {
    $r = genXmlConReferencia($this, 'genxml-nc-full', [
        referencia(['tipoDoc' => '99', 'tipoDocOtro' => 'Pedido', 'numero' => 'P&G<1>', 'razon' => 'Devolución A&B <urgente>']),
    ]);

    $nodos = nodosReferencia($r['xml']);
    expect($nodos['Razon'])->toBe('Devolución A&B <urgente>')
        ->and($nodos['Numero'])->toBe('P&G<1>');
});
