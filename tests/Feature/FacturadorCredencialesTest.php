<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\LegacySession;
use App\Models\User;

/**
 * Lo que los golden masters de credenciales y ambiente (74-78) no cubren:
 * el cambio de ambiente persiste, las rutas master ignoran un idMasterUser
 * ajeno y los campos que no existían en el EAV legacy no salen.
 */
function credencialesMaster(string $userName): array
{
    $user = User::create([
        'full_name' => $userName, 'user_name' => $userName, 'email' => "$userName@example.com",
        'about' => '{}', 'country' => 'crc', 'status' => '1', 'legacy_timestamp' => time(),
        'last_access' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => 'NULL',
    ]);
    Company::create(['id' => $user->id, 'owner_user_id' => $user->id]);
    LegacySession::create([
        'user_id' => $user->id, 'session_key' => "sk-$userName", 'ip' => '127.0.0.1', 'last_access' => time(),
    ]);

    return ['id' => $user->id, 'params' => ['w' => 'facturador', 'iam' => $userName, 'sessionKey' => "sk-$userName"]];
}

test('company_change_env guarda api-prod con "true" y api-stag con cualquier otro valor', function () {
    $master = credencialesMaster('ambiente');

    $this->post('/api.php', $master['params'] + ['r' => 'company_change_env', 'envProduccion' => 'true'])
        ->assertExactJson(['status' => 'ok', 'resp' => 'ok']);
    expect(Company::find($master['id'])->env)->toBe('api-prod');

    $this->post('/api.php', $master['params'] + ['r' => 'company_change_env', 'envProduccion' => 'false']);
    expect(Company::find($master['id'])->env)->toBe('api-stag');
});

test('get_prod_credentials responde con la empresa de la sesión aunque pidan otra', function () {
    $propia = credencialesMaster('propia');
    $ajena = credencialesMaster('ajena');
    HaciendaCredential::create(['company_id' => $propia['id'], 'environment' => 'prod', 'username' => 'mia']);
    HaciendaCredential::create(['company_id' => $ajena['id'], 'environment' => 'prod', 'username' => 'secreta']);

    $this->post('/api.php', $propia['params'] + ['r' => 'get_prod_credentials', 'idMasterUser' => (string) $ajena['id']])
        ->assertExactJson(['status' => 'ok', 'resp' => [['value' => 'mia']]]);
});

test('las credenciales omiten los campos null y sin ninguno responden -200', function () {
    $master = credencialesMaster('incompleta');
    HaciendaCredential::create([
        'company_id' => $master['id'], 'environment' => 'stag',
        'username' => 'usuario', 'password' => 'clave', 'p12_download_code' => '',
    ]);

    $this->post('/api.php', $master['params'] + ['r' => 'get_stag_credentials'])
        ->assertExactJson(['status' => 'ok', 'resp' => [['value' => 'usuario'], ['value' => 'clave'], ['value' => '']]]);

    expect($this->post('/api.php', $master['params'] + ['r' => 'get_prod_credentials', 'idMasterUser' => '0'])->json('resp'))
        ->toBe('-200');
});
