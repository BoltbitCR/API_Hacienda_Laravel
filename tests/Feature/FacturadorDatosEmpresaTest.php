<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CompanyUserSession;
use App\Models\Consecutive;
use App\Models\Document;
use App\Models\Receiver;

/**
 * Efectos que los golden masters de datos de empresa (88-97) no ven: qué
 * columnas se escriben, que las rutas master solo tocan la empresa de la
 * sesión y a quién quedan ligados contadores y comprobantes.
 */
function facturadorCajero(int $companyId, string $userName): array
{
    $user = CompanyUser::create(['company_id' => $companyId, 'user_name' => $userName, 'email' => "$userName@example.com"]);
    CompanyUserSession::create([
        'company_id' => $companyId, 'company_user_id' => $user->id, 'session_key' => "csk-$userName",
        'ip' => '127.0.0.1', 'last_access' => time(),
    ]);

    return ['id' => $user->id, 'params' => [
        'w' => 'facturador', 'idMasterUser' => (string) $companyId, 'iam' => $userName, 'sessionKey' => "csk-$userName",
    ]];
}

test('compannyUpdateInformation y compannyUpdateLocation editan solo la empresa de la sesión', function () {
    $propia = facturadorMaster('editora');
    $ajena = facturadorMaster('intacta');
    $otra = ['idMasterUser' => (string) $ajena['id']];

    $this->post('/api.php', $propia['params'] + $otra + [
        'r' => 'compannyUpdateInformation', 'nombre' => 'Mía S.A.', 'nombreComercial' => 'Mía',
        'email' => 'mia@example.com', 'codigoPais' => '507', 'telefono' => '22223333', 'cedula' => '3101000001',
    ])->assertExactJson(['status' => 'ok', 'resp' => 1]);
    $this->post('/api.php', $propia['params'] + $otra + [
        'r' => 'compannyUpdateLocation', 'idProvincia' => '4', 'idCanton' => '02', 'idDistrito' => '03',
        'idBarrio' => '04', 'sennas' => 'Frente al mercado',
    ])->assertExactJson(['status' => 'ok', 'resp' => 1]);

    $mia = Company::find($propia['id']);
    expect($mia->only(['nombre', 'nombre_comercial', 'email', 'tel_cod_pais', 'fax_cod_pais', 'tel_numero', 'fax_numero', 'cedula']))
        ->toBe([
            'nombre' => 'Mía S.A.', 'nombre_comercial' => 'Mía', 'email' => 'mia@example.com', 'tel_cod_pais' => '507',
            'fax_cod_pais' => '507', 'tel_numero' => '22223333', 'fax_numero' => '', 'cedula' => '3101000001',
        ])
        ->and($mia->only(['id_provincia', 'id_canton', 'id_distrito', 'id_barrio', 'sennas']))
        ->toBe(['id_provincia' => '4', 'id_canton' => '02', 'id_distrito' => '03', 'id_barrio' => '04', 'sennas' => 'Frente al mercado'])
        ->and(Company::find($ajena['id'])->nombre)->toBe('');
});

test('compannyUpdateLocation responde según SENNAS aunque cambien los demás campos', function () {
    $master = facturadorMaster('ubicacion');
    Company::whereKey($master['id'])->update(['sennas' => 'Igual']);

    $this->post('/api.php', $master['params'] + [
        'r' => 'compannyUpdateLocation', 'idMasterUser' => (string) $master['id'], 'idProvincia' => '5',
        'idCanton' => '01', 'idDistrito' => '01', 'idBarrio' => '01', 'sennas' => 'Igual',
    ])->assertExactJson(['status' => 'ok', 'resp' => '-200']);

    expect(Company::find($master['id'])->id_provincia)->toBe('5');
});

test('companny_add_master_Consecutive crea 14 contadores en cero y no liga un usuario ajeno', function () {
    $master = facturadorMaster('contadores');
    $ajena = facturadorMaster('ajena-contadores');
    $cajero = facturadorCajero($master['id'], 'cajero-c');
    $intruso = facturadorCajero($ajena['id'], 'intruso-c');
    $add = fn (int $idUser) => $this->post('/api.php', $master['params'] + [
        'r' => 'companny_add_master_Consecutive', 'idMasterUser' => (string) $master['id'], 'idUser' => (string) $idUser,
    ])->assertExactJson(['status' => 'ok', 'resp' => 14]);

    $add($cajero['id']);
    $add($intruso['id']);

    $propios = Consecutive::where('company_user_id', $cajero['id'])->get();
    expect($propios)->toHaveCount(14)
        ->and($propios->pluck('numero_consecutivo')->unique()->all())->toBe([0])
        ->and($propios->where('env', 'api-prod')->pluck('tipo_comprobante')->all())
        ->toBe(['FE', 'NC', 'ND', 'TE', 'CCE', 'CPCE', 'RCEFE'])
        ->and(Consecutive::where('company_id', $master['id'])->whereNull('company_user_id')->count())->toBe(14);
});

test('companny_updateConsecutive solo incrementa el contador del usuario de la sesión', function () {
    $master = facturadorMaster('incremento');
    $uno = facturadorCajero($master['id'], 'uno');
    $dos = facturadorCajero($master['id'], 'dos');
    foreach ([$uno, $dos] as $c) {
        Consecutive::create(['company_id' => $master['id'], 'env' => 'api-prod', 'tipo_comprobante' => 'TE', 'company_user_id' => $c['id']]);
    }

    $this->post('/api.php', $uno['params'] + ['r' => 'companny_updateConsecutive', 'tipoDocumento' => 'TE', 'env' => 'api-prod']);
    $this->post('/api.php', $uno['params'] + ['r' => 'companny_updateConsecutive', 'tipoDocumento' => 'TE', 'env' => 'api-prod']);

    expect(Consecutive::where('company_user_id', $uno['id'])->value('numero_consecutivo'))->toBe(2)
        ->and(Consecutive::where('company_user_id', $dos['id'])->value('numero_consecutivo'))->toBe(0);
});

test('companny_add_voucher guarda el comprobante y solo liga receptores de la empresa', function () {
    $master = facturadorMaster('comprobantes');
    $ajena = facturadorMaster('ajena-comprobantes');
    $cajero = facturadorCajero($master['id'], 'emisor');
    $propio = Receiver::create(['company_id' => $master['id']]);
    $extrano = Receiver::create(['company_id' => $ajena['id']]);
    $voucher = fn (Receiver $r, string $consecutivo) => $this->post('/api.php', $cajero['params'] + [
        'r' => 'companny_add_voucher', 'clave' => str_repeat('5', 50), 'consecutivo' => $consecutivo,
        'estado' => 'aceptado', 'xmlEnviadoBase64' => 'PHhtbC8+', 'tipoDocumento' => 'FE',
        'idComprobanteReferencia' => '7', 'idReceptor' => (string) $r->id, 'env' => 'api-stag',
    ])->assertExactJson(['status' => 'ok', 'resp' => 1]);

    $voucher($propio, '00100001010000000001');
    $voucher($extrano, '00100001010000000002');

    $doc = Document::where('consecutivo', '00100001010000000001')->sole();
    expect($doc->only(['company_id', 'company_user_id', 'receiver_id', 'documento_referencia', 'estado', 'env']))
        ->toBe([
            'company_id' => $master['id'], 'company_user_id' => $cajero['id'], 'receiver_id' => $propio->id,
            'documento_referencia' => '7', 'estado' => 'aceptado', 'env' => 'api-stag',
        ])
        ->and(Document::where('consecutivo', '00100001010000000002')->value('receiver_id'))->toBeNull();
});
