#!/usr/bin/env php
<?php
/**
 * Golden masters del módulo facturador. Requiere el stack legacy con las
 * "Tablas para Facturador CRLibre.sql" cargadas (catálogos + plantillas);
 * el usuario master goldenuser y su empresa los arma el propio script.
 *
 * Uso: php capture-facturador.php [baseUrl] [outDir]
 */

require __DIR__.'/capture-lib.php';

$baseUrl = $argv[1] ?? 'http://localhost:8080/api.php';
$outDir  = $argv[2] ?? dirname(__DIR__, 2).'/tests/Fixtures/golden';

// Rutas de catálogo/geo (open access, deterministas).
$cases = [
    ['name' => 'fact-provinces', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_all_privinces']],
    ['name' => 'fact-cantons', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_cantons', 'idProvince' => '1']],
    ['name' => 'fact-districts', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_district', 'idProvince' => '1', 'idCanton' => '01']],
    ['name' => 'fact-neighborhoods', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_neighborhood', 'idProvince' => '1', 'idCanton' => '01', 'idDistrito' => '01']],
    ['name' => 'fact-type-of-id', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_type_of_id']],
    ['name' => 'fact-location-info', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'getCompannyLocationInformation',
                  'idProvincia' => '1', 'idCanton' => '01', 'idDistrito' => '01', 'idBarrio' => '01']],
    ['name' => 'fact-info', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'info']],
    ['name' => 'fact-cantons-missing-param', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_cantons']],
];

$seq = 61;
foreach ($cases as $case) {
    $resp = callApi($baseUrl, $case);
    saveFixture($outDir, $seq++, $case, $resp);
}

/* --- Rutas de escritura: master user con sus tablas <id>_master_* y un
 *     company-user con sesión. La preparación no se guarda como fixture. --- */

$user = 'goldenuser';
$pwd  = 'Golden123*';

callApi($baseUrl, ['params' => ['w' => 'users', 'r' => 'users_register', 'fullName' => 'Golden User',
    'userName' => $user, 'email' => 'golden@example.com', 'about' => '{}', 'country' => 'crc', 'pwd' => $pwd]]);
$login = callApi($baseUrl, ['params' => ['w' => 'users', 'r' => 'users_log_me_in', 'userName' => $user, 'pwd' => $pwd]]);
$idMasterUser = (string) extractKey($login['body'], 'idUser');
$master = ['iam' => $user, 'sessionKey' => (string) extractKey($login['body'], 'sessionKey')];

callApi($baseUrl, ['params' => ['w' => 'facturador', 'r' => 'copy_master_tables'] + $master]);
callApi($baseUrl, ['params' => ['w' => 'facturador', 'r' => 'companny_users_register', 'fullName' => 'Golden Cajero',
    'userName' => 'goldencajero', 'email' => 'cajero@example.com', 'country' => 'crc', 'pwd' => $pwd,
    'idMasterUser' => $idMasterUser] + $master]);
$companyLogin = callApi($baseUrl, ['params' => ['w' => 'facturador', 'r' => 'companny_users_logMeIn',
    'idMasterUser' => $idMasterUser, 'userName' => 'goldencajero', 'pwd' => $pwd]]);
$company = ['idMasterUser' => $idMasterUser, 'sessionKey' => (string) extractKey($companyLogin['body'], 'sessionKey')];

$writeCases = [
    ['name' => 'fact-add-sucursal', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'addSucursales', 'numeroSucursal' => '002',
                  'nombreSucursal' => 'Sucursal Golden'] + $master],
    ['name' => 'fact-add-terminal', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'add_terminal', 'numeroTerminal' => '00002',
                  'nombreTerminal' => 'Caja Golden', 'idSucursal' => '1'] + $master],
    ['name' => 'fact-add-receptor', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'add_companny_reciver', 'nombreCliente' => 'Cliente Golden',
                  'numeroCedula' => '114480790', 'tipoCedula' => '01', 'telefono' => '88888888',
                  'idProvincia' => '1', 'idCanton' => '01', 'idDistrito' => '01', 'idBarrio' => '01',
                  'otrasSenas' => 'Frente al parque', 'nombreComercial' => 'Cliente Golden',
                  'correoPrincipal' => 'cliente@example.com'] + $company],
    ['name' => 'fact-delete-receptor', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'delete_reciver', 'idReceptor' => '1'] + $master],
    ['name' => 'fact-add-producto', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'addInventaryProduct', 'nombre' => 'Servicio Golden',
                  'descripcion' => 'Servicio profesional', 'unidadMedida' => 'Sp', 'precioVenta' => '10000',
                  'idImpuesto' => '1', 'cantidadImpuesto' => '13', 'codigoBarras' => '7501234567890',
                  'disponible' => '10', 'sucursal' => '001'] + $company],
];

foreach ($writeCases as $case) {
    $resp = callApi($baseUrl, $case);
    saveFixture($outDir, $seq++, $case, $resp);
}

/* --- Credenciales ATV y ambiente: leen los valores de plantilla que
 *     copy_master_tables dejó en <id>_master_config_companny. El cambio de
 *     ambiente va al final para no alterar las lecturas previas. --- */

$credentialCases = [
    ['name' => 'fact-get-stag-credentials', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_stag_credentials'] + $master],
    ['name' => 'fact-get-prod-credentials', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_prod_credentials', 'idMasterUser' => $idMasterUser] + $master],
    ['name' => 'fact-get-stag-companny-credentials', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_stag_companny_credentials'] + $company],
    ['name' => 'fact-get-prod-companny-credentials', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'get_prod_companny_credentials'] + $company],
    ['name' => 'fact-company-change-env', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'company_change_env', 'envProduccion' => 'true'] + $master],
];

foreach ($credentialCases as $case) {
    $resp = callApi($baseUrl, $case);
    saveFixture($outDir, $seq++, $case, $resp);
}

echo "\nListo.\n";
