<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Receiver;
use App\Models\Terminal;

/**
 * Las rutas de master (users_loggedIn) operan sobre la empresa de la sesión
 * aunque la petición traiga el idMasterUser de otra (#44). El gate solo
 * valida iam + sessionKey, así que el parámetro no puede decidir la empresa.
 */
beforeEach(function () {
    $this->propia = facturadorMaster('duena');
    $this->ajena = facturadorMaster('victima');
    $this->otra = ['idMasterUser' => (string) $this->ajena['id']];

    Company::whereKey($this->ajena['id'])->update(['nombre' => 'Secreta S.A.']);
    $this->branchAjena = Branch::create(['company_id' => $this->ajena['id'], 'nombre_sucursal' => 'Ajena', 'sucursal' => '009']);
    Terminal::create([
        'company_id' => $this->ajena['id'], 'branch_id' => $this->branchAjena->id,
        'nombre_terminal' => 'Caja ajena', 'terminal' => '00009',
    ]);
});

test('getSucursales y getTerminales no listan las de otra empresa', function () {
    $listar = fn (string $r) => $this->post('/api.php', $this->propia['params'] + $this->otra + ['r' => $r])->getContent();

    expect($listar('getSucursales'))->not->toContain('Ajena')
        ->and($listar('getTerminales'))->not->toContain('Caja ajena');
});

test('addSucursales y add_terminal crean en la empresa de la sesión', function () {
    $this->post('/api.php', $this->propia['params'] + $this->otra + [
        'r' => 'addSucursales', 'numeroSucursal' => '002', 'nombreSucursal' => 'Mía',
    ])->assertExactJson(['status' => 'ok', 'resp' => 1]);
    $this->post('/api.php', $this->propia['params'] + $this->otra + [
        'r' => 'add_terminal', 'numeroTerminal' => '00002', 'nombreTerminal' => 'Mi caja',
        'idSucursal' => (string) $this->branchAjena->id,
    ])->assertExactJson(['status' => 'ok', 'resp' => 1]);

    expect(Branch::where('company_id', $this->ajena['id'])->count())->toBe(1)
        ->and(Terminal::where('company_id', $this->ajena['id'])->count())->toBe(1)
        ->and(Branch::where('company_id', $this->propia['id'])->value('nombre_sucursal'))->toBe('Mía')
        ->and(Terminal::where('company_id', $this->propia['id'])->value('branch_id'))->toBeNull();
});

test('delete_reciver no desactiva receptores de otra empresa', function () {
    $receptor = Receiver::create(['company_id' => $this->ajena['id'], 'estado_cliente' => '1']);

    $this->post('/api.php', $this->propia['params'] + $this->otra + [
        'r' => 'delete_reciver', 'idReceptor' => (string) $receptor->id,
    ])->assertExactJson(['status' => 'ok', 'resp' => '-200']);

    expect($receptor->fresh()->estado_cliente)->toBe('1');
});

test('get_companny_information_admin responde la empresa de la sesión', function () {
    $nombre = collect($this->post('/api.php', $this->propia['params'] + $this->otra + ['r' => 'get_companny_information_admin'])
        ->json('resp'))->firstWhere('name', 'NOMBRE');

    expect($nombre['value'])->toBe('');
});
