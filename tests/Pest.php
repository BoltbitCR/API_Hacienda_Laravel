<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\LegacySession;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Parity', 'Unit');

/**
 * Master user legacy con su empresa (company_id == user id) y una sesión
 * viva, para llamar a /api.php?w=facturador como el dueño. Sin empresa es
 * un master recién registrado, antes de copy_master_tables.
 *
 * @return array{id: int, params: array<string, string>}
 */
function facturadorMaster(string $userName, bool $withCompany = true): array
{
    $user = User::create([
        'full_name' => $userName, 'user_name' => $userName, 'email' => "$userName@example.com",
        'about' => '{}', 'country' => 'crc', 'status' => '1', 'legacy_timestamp' => time(),
        'last_access' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => 'NULL',
    ]);
    if ($withCompany) {
        Company::create(['id' => $user->id, 'owner_user_id' => $user->id]);
    }
    LegacySession::create([
        'user_id' => $user->id, 'session_key' => "sk-$userName", 'ip' => '127.0.0.1', 'last_access' => time(),
    ]);

    return ['id' => $user->id, 'params' => ['w' => 'facturador', 'iam' => $userName, 'sessionKey' => "sk-$userName"]];
}

/**
 * Empresa emisora lista para emitir en STAG por la API v1: catálogos,
 * credenciales ATV y el .p12 de prueba del corpus golden (en claro).
 *
 * @return array{0: User, 1: Company, 2: string} usuario, empresa y token Sanctum
 */
function emisorDePrueba(array $companyOverrides = []): array
{
    (new CatalogSeeder)->run();

    $sufijo = bin2hex(random_bytes(3));
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => "owner-$sufijo", 'email' => "owner-$sufijo@example.com",
        'about' => '', 'country' => 'crc', 'status' => '1',
        'legacy_timestamp' => time(), 'last_access' => time(),
        'password' => password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);

    $company = Company::create(array_merge([
        'id' => $user->id, 'owner_user_id' => $user->id,
        'nombre' => 'ACME S.A.', 'tipo_cedula' => '02', 'cedula' => '3101234567',
        'nombre_comercial' => 'ACME', 'email' => 'facturas@acme.cr',
        'id_provincia' => '1', 'id_canton' => '01', 'id_distrito' => '01', 'id_barrio' => '01',
        'sennas' => 'Oficinas centrales', 'tel_cod_pais' => '506', 'tel_numero' => '22001100',
        'env' => 'api-stag', 'situacion' => 'normal', 'tipo_cambio' => '1',
    ], $companyOverrides));

    $dir = storage_path('app/legacy-files/'.$user->id.'/hacienda');
    @mkdir($dir, 0775, true);
    copy(base_path('legacy/golden/test-cert.p12'), $dir.'/cert.p12');

    StoredFile::create([
        'user_id' => $user->id, 'name' => 'cert.p12', 'download_code' => "p12code-$sufijo",
        'type' => 'hacienda', 'path' => 'legacy-files/'.$user->id.'/hacienda/cert.p12',
    ]);

    HaciendaCredential::create([
        'company_id' => $company->id, 'environment' => 'stag',
        'username' => 'cpj-3-101-234567@stag.comprobanteselectronicos.go.cr',
        'password' => 'atv-secret', 'p12_download_code' => "p12code-$sufijo", 'pin' => '1234',
    ]);

    return [$user, $company, $user->createToken('test', ['master'])->plainTextToken];
}

/** Hacienda falsa: IdP responde token y la recepción responde $recepcion. */
function haciendaFalsa(mixed $recepcion = null): void
{
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'bearer', 'expires_in' => 300], 200),
        '*recepcion*' => $recepcion ?? Http::response('', 202),
    ]);
}

/**
 * Cuerpo de una FE gravada al 13 % (1.000 + 130) que valida contra el XSD.
 *
 * @return array<string, mixed>
 */
function cuerpoFe(array $overrides = []): array
{
    return array_replace_recursive([
        'tipo' => 'FE',
        'environment' => 'stag',
        'fecha_emision' => '2026-10-17T10:00:00-06:00',
        'condicion_venta' => '01',
        'medios_pago' => [['tipoMedioPago' => '01', 'totalMedioPago' => 1130]],
        'detalles' => [[
            'codigoCABYS' => '8313100000100', 'cantidad' => 1, 'unidadMedida' => 'Sp',
            'detalle' => 'Servicio', 'precioUnitario' => 1000.00,
            'montoTotal' => 1000.00, 'subTotal' => 1000.00,
            'impuestoAsumidoEmisorFabrica' => 0, 'impuestoNeto' => 130.00,
            'montoTotalLinea' => 1130.00, 'baseImponible' => 1000.00,
            'impuesto' => [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0, 'monto' => 130.00]],
        ]],
        'total_ventas' => 1000.00,
        'total_ventas_neta' => 1000.00,
        'total_comprobante' => 1130.00,
        'params' => [
            'codigo_actividad_emisor' => '6201.0',
            'total_serv_gravados' => 1000.00,
            'total_gravado' => 1000.00,
            'total_impuestos' => 130.00,
            'totalDesgloseImpuesto' => json_encode([
                ['Codigo' => '01', 'CodigoTarifaIVA' => '08', 'TotalMontoImpuesto' => 130],
            ]),
        ],
        'receptor_nombre' => 'Cliente ABC',
        'receptor_tipo_identif' => '01',
        'receptor_num_identif' => '208830333',
        'receptor_email' => 'cliente@example.com',
    ], $overrides);
}
