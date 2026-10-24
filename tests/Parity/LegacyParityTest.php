<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CompanyUserSession;
use App\Models\Consecutive;
use App\Models\HaciendaCredential;
use App\Models\LegacySession;
use App\Models\Receiver;
use App\Models\StoredFile;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Parity\GoldenFixture;
use Tests\Support\XmlSignatureVerifier;

/**
 * Tests de paridad contra los golden masters capturados del API legacy
 * (tests/Fixtures/golden/, ver legacy/golden/README.md).
 *
 * Cada fixture se reproduce contra /api.php y se compara según su modo:
 *   exact  -> status + body byte a byte
 *   shape  -> status + estructura del JSON (valores aleatorios excluidos)
 *   signature -> status + shape; la validez criptográfica se prueba aparte
 *
 * IMPLEMENTED lista los fixtures cuyos módulos ya fueron portados; el resto
 * se marca como skipped para que el progreso del port sea visible sin
 * romper la suite.
 */
const IMPLEMENTED = [
    // Fase 1: tubería de compatibilidad
    'version',
    'version-get',
    'unknown-module',
    'unknown-route',
    'no-params-at-all',
    'invalid-json-body',
    'ejemplo-hola',

    // Fase 2: núcleo FE
    'clave-fe-fisico',
    'clave-te-juridico',
    'clave-nc-contingencia',
    'clave-nd-sininternet',
    'clave-missing-param',
    'genxml-test',
    'genxml-fe-full',
    'genxml-fe-minimal',
    'genxml-fe-json-body',
    'genxml-fe-missing-clave',
    'genxml-nc-full',
    'genxml-nd-full',
    'genxml-te-omitir-receptor',
    'genxml-te-con-receptor',
    'genxml-fec',
    'genxml-fee',
    'genxml-mr-aceptado',
    'genxml-mr-rechazado',
    'genxml-mr-missing-param',
    'makejson',
    'makejson-missing-param',
    'xmltobase64-bad-code',
    'makeqr',
    'firmar-fe',
    'firmar-bad-pin',
    'firmar-missing-p12',

    // Fase 3: integración Hacienda (los deterministas; ver LIVE_ONLY)
    'token-missing-params',
    'send-missing-params',
    'consultar-missing-params',
    'token-bad-client-id',
    'consultar-bad-client-id',
    'callback-hacienda',
    'token-fake-creds',
    'token-refresh-fake',
    'send-fake-token',
    'send-te-fake-token',
    'send-mensaje-fake-token',
    'consultar-fake-token',

    // Fase 4: users + files
    'users-register',
    'users-register-duplicate',
    'users-login-wrong-pwd',
    'users-login-ok',
    'users-login-email',
    'users-get-my-details',
    'users-confirm-session',
    'users-bad-session',
    'users-logout',
    'upload-cert',
    'xmltobase64-good-code',
    'crlibreall-fe-stub',
    'crypto-makekey',
    'crypto-encrypt-denied',
    'fact-info',
    'fact-provinces',
    'fact-cantons',
    'fact-districts',
    'fact-neighborhoods',
    'fact-type-of-id',
    'fact-location-info',
    'fact-cantons-missing-param',
    'fact-add-sucursal',
    'fact-add-terminal',
    'fact-add-receptor',
    'fact-delete-receptor',
    'fact-add-producto',
    'fact-get-stag-credentials',
    'fact-get-prod-credentials',
    'fact-get-stag-companny-credentials',
    'fact-get-prod-companny-credentials',
    'fact-company-change-env',
    'fact-companny-users-register',
    'fact-companny-users-register-duplicate',
    'fact-get-users-companny',
    'fact-get-user-permission-by-id',
    'fact-companny-users-recover-pwd-unknown',
    'fact-companny-users-recover-pwd',
    'fact-companny-users-update-profile',
    'fact-company-stag-users',
    'fact-company-prod-users',
    'fact-companny-update-tipo-cambio',
    'fact-companny-update-tipo-cambio-same',
    'fact-companny-update-location',
    'fact-companny-update-information',
    'fact-companny-get-my-info',
    'fact-companny-add-master-consecutive',
    'fact-companny-update-consecutive',
    'fact-companny-update-consecutive-none',
    'fact-companny-add-voucher',
    'fact-companny-add-voucher-sin-receptor',
];

/** Fixtures que requieren los catálogos MH sembrados. */
const NEEDS_CATALOGS = [
    'fact-provinces', 'fact-cantons', 'fact-districts', 'fact-neighborhoods',
    'fact-type-of-id', 'fact-location-info',
];

/** Rutas de escritura del facturador: requieren la empresa del goldenuser. */
const FACTURADOR_WRITES = [
    'fact-add-sucursal', 'fact-add-terminal', 'fact-add-receptor',
    'fact-delete-receptor', 'fact-add-producto',
];

/**
 * Credenciales ATV y ambiente: leen la plantilla que copy_master_tables
 * inserta en <id>_master_config_companny. La contraseña de prod sale
 * truncada porque el legacy interpola "$DcOP" dentro de comillas dobles.
 */
const FACTURADOR_CREDENTIALS = [
    'fact-get-stag-credentials', 'fact-get-prod-credentials',
    'fact-get-stag-companny-credentials', 'fact-get-prod-companny-credentials',
    'fact-company-change-env',
];

/**
 * Usuarios de empresa y permisos. Cuatro rutas mueren en el legacy (body
 * vacío): register tras crear al usuario, recover_pwd con un usuario que
 * existe, update_profile y getUserPermissionById siempre.
 */
const FACTURADOR_USERS = [
    'fact-companny-users-register', 'fact-companny-users-register-duplicate',
    'fact-get-users-companny', 'fact-get-user-permission-by-id',
    'fact-companny-users-recover-pwd-unknown', 'fact-companny-users-recover-pwd',
    'fact-companny-users-update-profile', 'fact-company-stag-users', 'fact-company-prod-users',
];

/**
 * Datos de empresa, consecutivos y comprobantes. Las rutas company corren con
 * la sesión de goldenvendedor (terminal 1); add_voucher sin idReceptor muere
 * con body vacío porque el legacy lo concatena sin comillas.
 */
const FACTURADOR_COMPANY_DATA = [
    'fact-companny-update-tipo-cambio', 'fact-companny-update-tipo-cambio-same',
    'fact-companny-update-location', 'fact-companny-update-information', 'fact-companny-get-my-info',
    'fact-companny-add-master-consecutive', 'fact-companny-update-consecutive',
    'fact-companny-update-consecutive-none', 'fact-companny-add-voucher', 'fact-companny-add-voucher-sin-receptor',
];

/** Fixtures que corren sobre la empresa del goldenuser. */
const FACTURADOR_COMPANY = [...FACTURADOR_WRITES, ...FACTURADOR_CREDENTIALS, ...FACTURADOR_USERS, ...FACTURADOR_COMPANY_DATA];

/**
 * Fixtures que golpean los endpoints reales de Hacienda (sandbox). Solo se
 * ejecutan con HACIENDA_LIVE_TESTS=1; la lógica de mapeo se cubre de forma
 * determinista en tests/Feature/HaciendaClientsTest.php con Http::fake().
 */
const LIVE_ONLY = [
    'token-fake-creds',
    'token-refresh-fake',
    'send-fake-token',
    'send-te-fake-token',
    'send-mensaje-fake-token',
    'consultar-fake-token',
];

/**
 * Fixtures cuyo comportamiento legacy era un bug irreproducible y se decidió
 * divergir deliberadamente (documentado en el módulo correspondiente).
 */
const INTENTIONALLY_DIVERGENT = [
    // El módulo check legacy cargaba un fac.xml hardcodeado contra XSD 4.2 y
    // volcaba warnings HTML; se reimplementó validando contra XSD v4.4.
    'check-xml-fe' => 'CheckModule reimplementado (legacy roto); ver app/Legacy/Modules/CheckModule.php',
];

/**
 * Normalizadores por fixture: neutralizan las partes de la respuesta que
 * dependen del momento de ejecución (documentado en cada caso).
 *
 * La clave numérica lleva la fecha local ddmmyy en las posiciones 4-9;
 * el resto es determinista.
 */
const NORMALIZERS = [
    'clave-fe-fisico' => 'normalizeClaveDate',
    'clave-te-juridico' => 'normalizeClaveDate',
    'clave-nc-contingencia' => 'normalizeClaveDate',
    'clave-nd-sininternet' => 'normalizeClaveDate',
];

function normalizeClaveDate(string $body): string
{
    return preg_replace('/("clave":")(\d{3})\d{6}/', '$1$2DDMMYY', $body);
}

/**
 * Modo signature: shape del JSON + verificación criptográfica del XML
 * firmado (la firma incluye SigningTime e IDs aleatorios, no hay paridad
 * byte a byte posible).
 */
function assertSignatureMatches(string $expectedBody, string $actualBody, string $context): void
{
    assertShapeMatches($expectedBody, $actualBody, $context);

    $actual = json_decode($actualBody, true);
    $signed = base64_decode($actual['resp']['xmlFirmado'] ?? '', true);
    expect($signed)->not->toBeFalse("[$context] xmlFirmado no es base64");

    $result = XmlSignatureVerifier::verify((string) $signed);
    expect($result['ok'])->toBeTrue("[$context] firma inválida: ".implode('; ', $result['errors']));
}

/**
 * Modo send-raw: la respuesta legacy de send incluye los headers HTTP crudos
 * de Hacienda en text[] y su cantidad varía entre ejecuciones. Se compara
 * status + que resp sea una lista de strings cuya primera línea es el
 * status-line HTTP con el mismo código que el golden.
 */
function assertSendRawMatches(string $expectedBody, string $actualBody, string $context): void
{
    $expected = json_decode($expectedBody, true);
    $actual = json_decode($actualBody, true);

    expect($actual)->not->toBeNull("[$context] la respuesta no es JSON");
    expect($actual['status'] ?? null)->toBe($expected['status'], "[$context] status difiere");
    expect($actual['resp'])->toBeArray("[$context] resp no es lista");
    expect($actual['resp'][0])->toStartWith('HTTP/', "[$context] primera línea no es status-line");

    preg_match('/HTTP\S* (\d{3})/', $expected['resp'][0], $me);
    preg_match('/HTTP\S* (\d{3})/', $actual['resp'][0], $ma);
    expect($ma[1] ?? null)->toBe($me[1] ?? null, "[$context] código HTTP de Hacienda difiere");
}

/**
 * Estado previo que cada fixture con BD necesita (los golden se capturaron
 * contra el legacy con datos vivos; aquí se recrean equivalentes).
 */
function seedFixtureState(GoldenFixture $f): void
{
    if (in_array($f->name, NEEDS_CATALOGS, true)) {
        (new CatalogSeeder)->run();
    }

    $needsGoldenUser = in_array($f->name, [
        'users-register-duplicate', 'users-login-wrong-pwd', 'users-login-ok',
        'users-login-email', 'users-get-my-details', 'users-confirm-session',
        'users-bad-session', 'users-logout', 'upload-cert',
        'xmltobase64-good-code', 'firmar-fe', 'firmar-bad-pin',
    ], true) || in_array($f->name, FACTURADOR_COMPANY, true);

    if (! $needsGoldenUser) {
        return;
    }

    $user = User::create([
        'full_name' => 'Golden User',
        'user_name' => 'goldenuser',
        'email' => 'golden@example.com',
        'about' => '{}',
        'country' => 'crc',
        'status' => '1',
        'legacy_timestamp' => time(),
        'last_access' => time(),
        'password' => password_hash('Golden123*', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0',
        'settings' => 'NULL',
    ]);

    // Sesión válida para la sessionKey exacta que viaja en el request capturado.
    $sessionKey = $f->params['sessionKey'] ?? null;
    if ($sessionKey !== null && $f->name !== 'users-bad-session') {
        LegacySession::create([
            'user_id' => $user->id,
            'session_key' => $sessionKey,
            'ip' => '127.0.0.1',
            'last_access' => time(),
        ]);
    }

    // Empresa del master user (mismo id, como en el legacy). En las rutas de
    // acceso company, la sessionKey capturada es la del company-user.
    if (in_array($f->name, FACTURADOR_COMPANY, true)) {
        $company = Company::create(['id' => $user->id, 'owner_user_id' => $user->id]);

        $needsCompanyUsers = isset($f->params['idMasterUser'])
            || in_array($f->name, [...FACTURADOR_USERS, ...FACTURADOR_COMPANY_DATA], true);
        if ($needsCompanyUsers) {
            $companyUser = CompanyUser::create([
                'company_id' => $company->id, 'user_name' => 'goldencajero', 'email' => 'cajero@example.com',
            ]);
        }

        // goldenvendedor (id 2) ya registrado y ligado a la terminal 1.
        $iam = $f->params['iam'] ?? null;
        if ($iam === 'goldenvendedor' || in_array($f->name, ['fact-companny-users-register-duplicate', 'fact-get-users-companny'], true)) {
            $branch = Branch::create(['company_id' => $company->id, 'nombre_sucursal' => 'Sucursal Golden', 'sucursal' => '002']);
            $terminal = Terminal::create([
                'company_id' => $company->id, 'branch_id' => $branch->id,
                'nombre_terminal' => 'Caja Golden', 'terminal' => '00002',
            ]);
            $vendedor = CompanyUser::create([
                'company_id' => $company->id, 'full_name' => 'Golden Vendedor', 'user_name' => 'goldenvendedor',
                'email' => 'vendedor@example.com', 'country' => 'crc', 'settings' => (string) $terminal->id,
            ]);
            if ($iam === 'goldenvendedor') {
                $companyUser = $vendedor;
            }
        }

        // La sesión es del company-user que viaja en iam (goldencajero si no
        // viaja). recover_pwd es open access: viaja sin sessionKey.
        if ($needsCompanyUsers && $sessionKey !== null) {
            CompanyUserSession::create([
                'company_id' => $company->id,
                'company_user_id' => $companyUser->id,
                'session_key' => $sessionKey,
                'ip' => '127.0.0.1',
                'last_access' => time(),
            ]);
        }

        if ($f->name === 'fact-companny-update-tipo-cambio-same') {
            $company->update(['tipo_cambio' => '540.25']);
        }

        if ($f->name === 'fact-companny-update-consecutive') {
            Consecutive::create([
                'company_id' => $company->id, 'env' => 'api-stag', 'tipo_comprobante' => 'FE',
                'company_user_id' => $companyUser->id,
            ]);
        }

        if (in_array($f->name, FACTURADOR_CREDENTIALS, true)) {
            foreach (['stag' => '1PdeUpreble', 'prod' => 'N&@4+p[H-e[+#@9'] as $env => $password) {
                HaciendaCredential::create([
                    'company_id' => $company->id, 'environment' => $env,
                    'username' => 'cpf-07-0232-0717@stag.comprobanteselectronicos.go.cr',
                    'password' => $password, 'p12_download_code' => '', 'pin' => '1994',
                ]);
            }
        }

        if ($f->name === 'fact-delete-receptor') {
            Receiver::create(['id' => $f->params['idReceptor'], 'company_id' => $company->id]);
        }
    }

    // El p12 de prueba registrado con el downloadCode del request capturado.
    $downloadCode = $f->params['p12Url'] ?? $f->params['downloadCode'] ?? null;
    if ($downloadCode !== null && in_array($f->name, ['firmar-fe', 'firmar-bad-pin', 'xmltobase64-good-code'], true)) {
        $dir = storage_path('app/legacy-files/'.$user->id.'/hacienda');
        @mkdir($dir, 0775, true);
        copy(base_path('legacy/golden/test-cert.p12'), $dir.'/test-cert.p12');
        StoredFile::create([
            'user_id' => $user->id,
            'name' => 'test-cert.p12',
            'download_code' => $downloadCode,
            'type' => 'hacienda',
            'path' => 'legacy-files/'.$user->id.'/hacienda/test-cert.p12',
        ]);
    }
}

function callFixture(GoldenFixture $f): TestResponse
{
    $test = test();
    $uri = '/api.php'.($f->query ? '?'.http_build_query($f->query) : '');

    if ($f->bodyMode === 'rawjson') {
        return $test->call(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $f->rawBody,
        );
    }

    $files = [];
    foreach ($f->files ?? [] as $field => $basename) {
        $files[$field] = new UploadedFile(
            base_path('legacy/golden/'.$basename),
            $basename,
            'application/octet-stream',
            test: true,
        );
    }

    return $test->call($f->method, $uri, $f->params ?? [], [], $files);
}

function assertShapeMatches(string $expectedBody, string $actualBody, string $context): void
{
    $expected = json_decode($expectedBody, true);
    $actual = json_decode($actualBody, true);

    expect($actual)->not->toBeNull("[$context] la respuesta no es JSON: ".substr($actualBody, 0, 200));
    expect(shapeOf($actual))->toBe(shapeOf($expected), "[$context] la estructura del JSON difiere");

    // El campo status sí debe coincidir exactamente.
    if (is_array($expected) && array_key_exists('status', $expected)) {
        expect($actual['status'] ?? null)->toBe($expected['status'], "[$context] status difiere");
    }
}

/** Estructura recursiva de claves y tipos, ignorando valores. */
function shapeOf(mixed $value): mixed
{
    if (! is_array($value)) {
        return gettype($value);
    }

    $shape = [];
    foreach ($value as $k => $v) {
        $shape[$k] = shapeOf($v);
    }
    ksort($shape);

    return $shape;
}

foreach (GoldenFixture::all() as $name => $fixture) {
    test("paridad legacy: {$fixture->file}", function () use ($fixture) {
        if (isset(INTENTIONALLY_DIVERGENT[$fixture->name])) {
            $this->markTestSkipped('Divergencia deliberada: '.INTENTIONALLY_DIVERGENT[$fixture->name]);
        }

        if (! in_array($fixture->name, IMPLEMENTED, true)) {
            $this->markTestSkipped("Módulo aún no portado: {$fixture->name}");
        }

        if (in_array($fixture->name, LIVE_ONLY, true) && ! env('HACIENDA_LIVE_TESTS')) {
            $this->markTestSkipped('Requiere red hacia Hacienda; ejecutar con HACIENDA_LIVE_TESTS=1');
        }

        seedFixtureState($fixture);

        $response = callFixture($fixture);

        expect($response->getStatusCode())->toBe(
            $fixture->expectedStatus,
            "[{$fixture->name}] HTTP status difiere. Body: ".substr($response->getContent(), 0, 300)
        );

        $normalize = NORMALIZERS[$fixture->name] ?? null;
        $expectedBody = $normalize ? $normalize($fixture->expectedBody) : $fixture->expectedBody;
        $actualBody = $normalize ? $normalize($response->getContent()) : $response->getContent();

        match ($fixture->compare) {
            'exact', 'json-exact' => expect($actualBody)->toBe($expectedBody, "[{$fixture->name}] body difiere"),
            'shape' => assertShapeMatches($fixture->expectedBody, $response->getContent(), $fixture->name),
            'send-raw' => assertSendRawMatches($fixture->expectedBody, $response->getContent(), $fixture->name),
            'signature' => assertSignatureMatches($fixture->expectedBody, $response->getContent(), $fixture->name),
            'status-only' => null,
            default => throw new RuntimeException("Modo compare desconocido: {$fixture->compare}"),
        };
    });
}
