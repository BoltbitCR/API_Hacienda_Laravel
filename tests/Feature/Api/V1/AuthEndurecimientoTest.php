<?php

/**
 * Candados de una instancia privada: registro apagable y tokens con vida.
 */
test('con AUTH_REGISTRATION_ENABLED=false el registro responde 403', function () {
    config(['auth.registration_enabled' => false]);

    $this->postJson('/api/v1/auth/register', [
        'full_name' => 'Nadie', 'username' => 'nadie', 'email' => 'nadie@example.com', 'password' => 'secret123',
    ])->assertStatus(403);

    $this->assertDatabaseMissing('users', ['user_name' => 'nadie']);
});

test('el registro sigue abierto por defecto', function () {
    $this->postJson('/api/v1/auth/register', [
        'full_name' => 'Alguien', 'username' => 'alguien', 'email' => 'alguien@example.com', 'password' => 'secret123',
    ])->assertStatus(201);
});

test('un token vencido según SANCTUM_EXPIRATION no autentica', function () {
    config(['sanctum.expiration' => 60]);
    [$user] = emisorDePrueba();
    $token = $user->createToken('test', ['master'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->travel(61)->minutes();
    app('auth')->forgetGuards(); // el guard de la app de pruebas cachea al usuario entre peticiones
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});
