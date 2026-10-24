<?php

/**
 * El endpoint de compatibilidad /api.php solo responde con
 * LEGACY_API_ENABLED=true (phpunit.xml lo enciende para la paridad).
 */
test('apagado, /api.php responde 404 por GET y por POST', function () {
    config(['hacienda.legacy.enabled' => false]);

    $this->get('/api.php?w=facturador&r=info')->assertNotFound();
    $this->post('/api.php', ['w' => 'facturador', 'r' => 'info'])->assertNotFound();
});

test('encendido, /api.php despacha al módulo legacy', function () {
    config(['hacienda.legacy.enabled' => true]);

    $this->post('/api.php', ['w' => 'facturador', 'r' => 'info'])
        ->assertOk()
        ->assertJsonPath('resp.info', 'Modulo de interface de facturacion');
});
