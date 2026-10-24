<?php

use App\Models\Company;
use App\Models\HaciendaCredential;

/**
 * copy_master_tables da de alta la empresa del master. El legacy la llenaba
 * con la plantilla de CRLibre; aquí nace vacía y con credenciales en blanco.
 */
test('copy_master_tables crea la empresa del master con credenciales en blanco', function () {
    $master = facturadorMaster('nuevo', withCompany: false);

    $this->post('/api.php', $master['params'] + ['r' => 'copy_master_tables'])
        ->assertJsonPath('resp.0', 'master_logs');

    $company = Company::find($master['id']);
    expect($company->owner_user_id)->toBe($master['id'])
        ->and($company->env)->toBe('api-stag')
        ->and($company->nombre)->toBe('')
        ->and(HaciendaCredential::where('company_id', $master['id'])->orderBy('environment')->pluck('environment')->all())
        ->toBe(['prod', 'stag']);

    $this->post('/api.php', $master['params'] + ['r' => 'get_stag_credentials'])
        ->assertExactJson(['status' => 'ok', 'resp' => [['value' => ''], ['value' => ''], ['value' => ''], ['value' => '']]]);
});

test('copy_master_tables repetido muere sin duplicar la empresa ni las credenciales', function () {
    $master = facturadorMaster('repite', withCompany: false);
    $this->post('/api.php', $master['params'] + ['r' => 'copy_master_tables']);

    expect($this->post('/api.php', $master['params'] + ['r' => 'copy_master_tables'])->getContent())->toBe('')
        ->and(Company::where('owner_user_id', $master['id'])->count())->toBe(1)
        ->and(HaciendaCredential::where('company_id', $master['id'])->count())->toBe(2);
});
