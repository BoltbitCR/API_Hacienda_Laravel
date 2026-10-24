<?php

use App\Models\Branch;
use App\Models\CompanyUser;
use App\Models\HaciendaCredential;
use App\Models\Terminal;

/**
 * Efectos que los golden masters de usuarios de empresa (79-87) no ven:
 * el alta ocurre aunque el body salga vacío, recover_pwd no toca la clave,
 * y cada ambiente guarda su propio PIN (el legacy de prod lo escribía en stag).
 */
test('companny_users_register crea al usuario en la empresa de la sesión aunque pidan otra', function () {
    $propia = facturadorMaster('alta');
    $ajena = facturadorMaster('otra');

    $response = $this->post('/api.php', $propia['params'] + [
        'r' => 'companny_users_register', 'fullName' => 'Nueva Cajera', 'userName' => 'cajera',
        'email' => 'cajera@example.com', 'country' => 'crc', 'pwd' => 'Secreta1*',
        'settings' => '7', 'idMasterUser' => (string) $ajena['id'],
    ]);

    expect($response->getContent())->toBe('');
    $cajera = CompanyUser::where('user_name', 'cajera')->sole();
    expect($cajera->company_id)->toBe($propia['id'])
        ->and($cajera->settings)->toBe('7')
        ->and($cajera->about)->toBe('May all beings be at ease')
        ->and(password_verify('Secreta1*', $cajera->password))->toBeTrue();
});

test('companny_users_register rechaza un correo repetido aunque el usuario sea nuevo', function () {
    $master = facturadorMaster('repetido');
    CompanyUser::create(['company_id' => $master['id'], 'user_name' => 'primero', 'email' => 'mismo@example.com']);

    $this->post('/api.php', $master['params'] + [
        'r' => 'companny_users_register', 'fullName' => 'Segundo', 'userName' => 'segundo',
        'email' => 'mismo@example.com', 'country' => 'crc', 'pwd' => 'x', 'idMasterUser' => (string) $master['id'],
    ])->assertExactJson(['status' => 'ok', 'resp' => ['code' => '-304', 'status' => 'usuario ya existe']]);

    expect(CompanyUser::where('company_id', $master['id'])->count())->toBe(1);
});

test('companny_users_recover_pwd no cambia la clave del usuario', function () {
    $master = facturadorMaster('recupera');
    $hash = password_hash('Original1*', PASSWORD_DEFAULT);
    CompanyUser::create(['company_id' => $master['id'], 'user_name' => 'olvidadizo', 'email' => 'o@example.com', 'password' => $hash]);

    $response = $this->post('/api.php', [
        'w' => 'facturador', 'r' => 'companny_users_recover_pwd', 'userName' => 'o@example.com',
        'idMasterUser' => (string) $master['id'],
    ]);

    expect($response->getContent())->toBe('')
        ->and(CompanyUser::where('user_name', 'olvidadizo')->value('password'))->toBe($hash);
});

test('getUsersCompanny omite a los usuarios sin terminal y sin ninguno responde -200', function () {
    $master = facturadorMaster('listado');
    $call = fn () => $this->post('/api.php', $master['params'] + ['r' => 'getUsersCompanny']);
    CompanyUser::create(['company_id' => $master['id'], 'user_name' => 'sin-terminal', 'email' => 's@example.com', 'settings' => '']);

    expect($call()->json('resp'))->toBe('-200');

    $branch = Branch::create(['company_id' => $master['id'], 'nombre_sucursal' => 'Central', 'sucursal' => '001']);
    $terminal = Terminal::create(['company_id' => $master['id'], 'branch_id' => $branch->id, 'nombre_terminal' => 'Caja 1', 'terminal' => '00001']);
    CompanyUser::create(['company_id' => $master['id'], 'user_name' => 'con-terminal', 'email' => 'c@example.com', 'settings' => (string) $terminal->id]);

    expect($call()->json('resp'))->toHaveCount(1)
        ->and($call()->json('resp.0.nombreTerminal'))->toBe('Caja 1');
});

test('company_prod_users y company_stag_users guardan cada PIN en su ambiente', function () {
    $master = facturadorMaster('pines');
    $set = fn (string $r, string $pin) => $this->post('/api.php', $master['params'] + [
        'r' => $r, 'userName' => "usuario-$pin", 'password' => "clave-$pin", 'pinCerti' => $pin, 'downloadCode' => "codigo-$pin",
    ])->assertExactJson(['status' => 'ok', 'resp' => 'ok']);

    $set('company_stag_users', '1111');
    $set('company_prod_users', '2222');

    $stag = HaciendaCredential::where('company_id', $master['id'])->where('environment', 'stag')->sole();
    $prod = HaciendaCredential::where('company_id', $master['id'])->where('environment', 'prod')->sole();
    expect([$stag->username, $stag->password, $stag->p12_download_code, $stag->pin])
        ->toBe(['usuario-1111', 'clave-1111', 'codigo-1111', '1111'])
        ->and([$prod->username, $prod->password, $prod->p12_download_code, $prod->pin])
        ->toBe(['usuario-2222', 'clave-2222', 'codigo-2222', '2222']);
});
