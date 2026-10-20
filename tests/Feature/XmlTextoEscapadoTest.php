<?php

use Tests\Parity\GoldenFixture;

/**
 * Los textos libres (nombres, señas, detalle) pueden traer `&`, `<` o `>`.
 * Concatenados crudos dejaban el XML mal formado y la firma fallaba con
 * "xmlParseEntityRef: no name". Reusa los golden y cambia solo esos textos.
 */
const TEXTO_RECEPTOR_ESCAPE = 'INVERSIONES OCAMPO & VIZCAINO SOCIEDAD ANONIMA';
const TEXTO_SENAS_ESCAPE = 'Frente al parque <costado norte> & 50 m sur';
const TEXTO_DETALLE_ESCAPE = 'Casado con carne & ensalada <grande>';

/** @return array{status: int, body: array<mixed>, xml: ?string} */
function genXmlConTextosEspeciales($test, string $golden): array
{
    $params = GoldenFixture::all()[$golden]->params;

    $detalles = json_decode($params['detalles'], true);
    $detalles[0]['detalle'] = TEXTO_DETALLE_ESCAPE;

    $params = array_merge($params, [
        'receptor_nombre' => TEXTO_RECEPTOR_ESCAPE,
        'emisor_otras_senas' => TEXTO_SENAS_ESCAPE,
        'detalles' => json_encode($detalles),
    ]);

    $response = $test->call('POST', '/api.php', $params);
    $body = json_decode($response->getContent(), true) ?? [];
    $xml = isset($body['resp']['xml']) ? base64_decode($body['resp']['xml']) : null;

    return ['status' => $response->getStatusCode(), 'body' => $body, 'xml' => $xml];
}

function textoDeNodoEscape(DOMDocument $dom, string $padre, string $hijo): ?string
{
    $nodo = $dom->getElementsByTagName($padre)->item(0);
    $nodo = $nodo?->getElementsByTagName($hijo)->item(0);

    return $nodo?->textContent;
}

test('escapa los textos libres y el XML queda bien formado', function (string $golden) {
    $r = genXmlConTextosEspeciales($this, $golden);

    expect($r['xml'])->not->toBeNull(json_encode($r['body']));

    $dom = new DOMDocument;
    expect(@$dom->loadXML($r['xml']))->toBeTrue('XML mal formado');

    expect(textoDeNodoEscape($dom, 'Receptor', 'Nombre'))->toBe(TEXTO_RECEPTOR_ESCAPE)
        ->and(textoDeNodoEscape($dom, 'Emisor', 'OtrasSenas'))->toBe(TEXTO_SENAS_ESCAPE)
        ->and(textoDeNodoEscape($dom, 'LineaDetalle', 'Detalle'))->toBe(TEXTO_DETALLE_ESCAPE);
})->with([
    'genxml-fe-full',
    'genxml-nc-full',
    'genxml-nd-full',
    'genxml-te-con-receptor',
    'genxml-fec',
    'genxml-fee',
]);

test('sin caracteres especiales el texto sale igual que antes', function () {
    expect(App\Services\Xml\Legacy\_xml('Cliente ABC 123.45'))->toBe('Cliente ABC 123.45')
        ->and(App\Services\Xml\Legacy\_xml(13.65))->toBe('13.65')
        ->and(App\Services\Xml\Legacy\_xml(null))->toBe('')
        ->and(App\Services\Xml\Legacy\_xml('A & B <C>'))->toBe('A &amp; B &lt;C&gt;');
});
