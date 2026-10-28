<?php

use App\Services\Telemetry\TelemetryPinger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Telemetría anónima: phpunit.xml la apaga (TELEMETRY_ENABLED=false); cada
 * test la enciende contra un colector falso.
 */
beforeEach(function () {
    File::delete(storage_path('app/telemetry/instance-id'));
    config(['telemetry.url' => 'https://colector.test/ping']);
    Http::fake(['colector.test/*' => Http::response(['ok' => true])]);
});

afterEach(function () {
    File::delete(storage_path('app/telemetry/instance-id'));
});

test('apagada, no envía nada', function () {
    config(['telemetry.enabled' => false]);

    app(TelemetryPinger::class)->maybePing();

    Http::assertNothingSent();
});

test('encendida, envía una sola vez por intervalo', function () {
    config(['telemetry.enabled' => true]);
    $pinger = app(TelemetryPinger::class);

    $pinger->maybePing();
    $pinger->maybePing();

    Http::assertSentCount(1);
});

test('el envío lleva solo id, versión y PHP', function () {
    config(['telemetry.enabled' => true, 'telemetry.version' => '1.2.3']);

    app(TelemetryPinger::class)->maybePing();

    Http::assertSent(function ($request) {
        $data = $request->data();

        return array_keys($data) === ['id', 'version', 'php']
            && Str::isUuid($data['id'])
            && $data['version'] === '1.2.3';
    });
});

test('el identificador se conserva entre envíos', function () {
    $pinger = app(TelemetryPinger::class);

    expect($pinger->instanceId())->toBe($pinger->instanceId());
});

test('un colector caído no rompe nada', function () {
    config(['telemetry.enabled' => true]);
    Http::fake(['colector.test/*' => fn () => throw new RuntimeException('sin red')]);

    app(TelemetryPinger::class)->maybePing();

    expect(true)->toBeTrue();
});
