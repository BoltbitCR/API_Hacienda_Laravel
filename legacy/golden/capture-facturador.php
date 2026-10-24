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

/* --- Usuarios de empresa y permisos. goldenvendedor queda ligado a la
 *     terminal 1 (settings) para que getUsersCompanny tenga qué listar; las
 *     escrituras de credenciales van al final. --- */

$vendedor = ['w' => 'facturador', 'r' => 'companny_users_register', 'fullName' => 'Golden Vendedor',
    'userName' => 'goldenvendedor', 'email' => 'vendedor@example.com', 'country' => 'crc', 'pwd' => $pwd,
    'settings' => '1', 'idMasterUser' => $idMasterUser] + $master;

$userCases = [
    ['name' => 'fact-companny-users-register', 'compare' => 'exact', 'params' => $vendedor],
    ['name' => 'fact-companny-users-register-duplicate', 'compare' => 'exact', 'params' => $vendedor],
    ['name' => 'fact-get-users-companny', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'getUsersCompanny'] + $master],
    ['name' => 'fact-get-user-permission-by-id', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'getUserPermissionById'] + $master],
    ['name' => 'fact-companny-users-recover-pwd-unknown', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_users_recover_pwd', 'userName' => 'nadie',
                  'idMasterUser' => $idMasterUser]],
    ['name' => 'fact-companny-users-recover-pwd', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_users_recover_pwd', 'userName' => 'goldencajero',
                  'idMasterUser' => $idMasterUser]],
    ['name' => 'fact-companny-users-update-profile', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_users_update_profile', 'fullName' => 'Cajero Renombrado',
                  'userName' => 'goldencajero', 'email' => 'cajero@example.com'] + $company],
    ['name' => 'fact-company-stag-users', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'company_stag_users', 'userName' => 'cpj-3-101-000000@stag.comprobanteselectronicos.go.cr',
                  'password' => 'ClaveStag1', 'pinCerti' => '4321', 'downloadCode' => 'codigo-stag'] + $master],
    ['name' => 'fact-company-prod-users', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'company_prod_users', 'userName' => 'cpj-3-101-000000@prod.comprobanteselectronicos.go.cr',
                  'password' => 'ClaveProd1', 'pinCerti' => '8765', 'downloadCode' => 'codigo-prod'] + $master],
];

foreach ($userCases as $case) {
    $resp = callApi($baseUrl, $case);
    saveFixture($outDir, $seq++, $case, $resp);
}

/* --- Datos de empresa, consecutivos y comprobantes. goldenvendedor (id 2,
 *     terminal 1) inicia sesión para las rutas company; goldencajero no
 *     tiene terminal ni consecutivos. --- */

$vendedorLogin = callApi($baseUrl, ['params' => ['w' => 'facturador', 'r' => 'companny_users_logMeIn',
    'idMasterUser' => $idMasterUser, 'userName' => 'goldenvendedor', 'pwd' => $pwd]]);
$companyVendedor = ['idMasterUser' => $idMasterUser, 'iam' => 'goldenvendedor',
    'sessionKey' => (string) extractKey($vendedorLogin['body'], 'sessionKey')];

$voucher = ['w' => 'facturador', 'r' => 'companny_add_voucher', 'clave' => str_repeat('5', 50),
    'consecutivo' => '00100001010000000001', 'estado' => 'aceptado', 'xmlEnviadoBase64' => 'PHhtbC8+',
    'tipoDocumento' => 'FE', 'respuestaMHBase64' => 'PHJlc3B1ZXN0YS8+', 'env' => 'api-stag'];

$companyCases = [
    ['name' => 'fact-companny-update-tipo-cambio', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'compannyUpdateTipoCambio', 'idMasterUser' => $idMasterUser,
                  'tipoCambio' => '540.25'] + $master],
    ['name' => 'fact-companny-update-tipo-cambio-same', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'compannyUpdateTipoCambio', 'idMasterUser' => $idMasterUser,
                  'tipoCambio' => '540.25'] + $master],
    ['name' => 'fact-companny-update-location', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'compannyUpdateLocation', 'idMasterUser' => $idMasterUser,
                  'idProvincia' => '2', 'idCanton' => '01', 'idDistrito' => '01', 'idBarrio' => '01',
                  'sennas' => 'Costado norte de la iglesia'] + $master],
    ['name' => 'fact-companny-update-information', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'compannyUpdateInformation', 'idMasterUser' => $idMasterUser,
                  'nombre' => 'Golden S.A.', 'nombreComercial' => 'Golden', 'email' => 'empresa@example.com',
                  'codigoPais' => '506', 'telefono' => '22223333', 'fax' => '22224444', 'tipoCedula' => '02',
                  'cedula' => '3101123456'] + $master],
    ['name' => 'fact-companny-get-my-info', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_getMyInfo'] + $companyVendedor],
    ['name' => 'fact-companny-add-master-consecutive', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_add_master_Consecutive', 'idMasterUser' => $idMasterUser,
                  'idUser' => '2'] + $master],
    ['name' => 'fact-companny-update-consecutive', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_updateConsecutive', 'tipoDocumento' => 'FE',
                  'env' => 'api-stag'] + $companyVendedor],
    ['name' => 'fact-companny-update-consecutive-none', 'compare' => 'exact',
     'params' => ['w' => 'facturador', 'r' => 'companny_updateConsecutive', 'tipoDocumento' => 'FE',
                  'env' => 'api-stag'] + $company],
    ['name' => 'fact-companny-add-voucher', 'compare' => 'exact',
     'params' => $voucher + ['idReceptor' => '1'] + $companyVendedor],
    ['name' => 'fact-companny-add-voucher-sin-receptor', 'compare' => 'exact',
     'params' => $voucher + $companyVendedor],
];

foreach ($companyCases as $case) {
    $resp = callApi($baseUrl, $case);
    saveFixture($outDir, $seq++, $case, $resp);
}

echo "\nListo.\n";
