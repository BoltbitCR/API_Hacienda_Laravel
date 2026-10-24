<?php

namespace App\Services\Facturador;

use App\Legacy\LegacyErrorCode;
use App\Legacy\LegacyParams;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUserSession;
use App\Models\Consecutive;
use App\Models\Document;
use App\Models\LegacySession;
use App\Models\Product;
use App\Models\Receiver;
use App\Models\Terminal;
use App\Services\Auth\CompanyUserAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Handlers del módulo facturador, portados de facturadorCRLibre.php y
 * companny_user.php sobre el esquema Eloquent normalizado.
 *
 * Contrato conservado: los valores de BD viajan como strings; los catálogos
 * devuelven arrays de objetos; los inserts devuelven affected_rows-like.
 * El idMasterUser legacy == company_id (== users.id del dueño).
 */
class FacturadorService
{
    public const MODULE_VERSION = 'V.0.0';

    public function __construct(private readonly CompanyUserAuthService $companyAuth) {}

    /**
     * Mapa r => handler. Solo las rutas listadas aquí quedan activas; el
     * resto de la tabla legacy cae en "Function not found" hasta portarse.
     *
     * @return array<string, \Closure(LegacyParams): mixed>
     */
    public function routeHandlers(): array
    {
        return [
            'info' => fn () => ['info' => 'Modulo de interface de facturacion', 'version' => self::MODULE_VERSION],

            // --- Catálogos globales / geografía (open access) ---
            'get_all_privinces' => fn () => $this->provinces(),
            'get_cantons' => fn (LegacyParams $p) => $this->cantons((string) $p->get('idProvince')),
            'get_district' => fn (LegacyParams $p) => $this->districts((string) $p->get('idProvince'), (string) $p->get('idCanton')),
            'get_neighborhood' => fn (LegacyParams $p) => $this->neighborhoods((string) $p->get('idProvince'), (string) $p->get('idCanton'), (string) $p->get('idDistrito')),
            'get_type_of_id' => fn () => $this->typeOfId(),
            'getCompannyLocationInformation' => fn (LegacyParams $p) => $this->locationInfo($p),

            // --- Autenticación de sub-usuarios de empresa ---
            'companny_users_logMeIn' => fn (LegacyParams $p) => $this->companyLogin($p),
            'users_log_me_out' => fn (LegacyParams $p) => $this->companyLogout($p),
            'companny_users_getMyDetails' => fn (LegacyParams $p) => $this->companyMyDetails($p),
            'companny_users_get_my_details' => fn (LegacyParams $p) => $this->companyMyDetails($p),

            // --- Info de empresa (company access) ---
            'get_companny_information' => fn (LegacyParams $p) => $this->companyInformation((int) $p->get('idMasterUser')),
            'get_companny_information_admin' => fn (LegacyParams $p) => $this->companyInformation((int) $p->get('idMasterUser')),
            'company_get_env' => fn (LegacyParams $p) => $this->companyGetEnv((int) $p->get('idMasterUser')),

            // --- Sucursales / terminales ---
            'getSucursales' => fn (LegacyParams $p) => $this->branches($p),
            'getTerminales' => fn (LegacyParams $p) => $this->terminals($p),
            'addSucursales' => fn (LegacyParams $p) => $this->addBranch($p),
            'add_terminal' => fn (LegacyParams $p) => $this->addTerminal($p),

            // --- Receptores ---
            'get_active_receiver' => fn (LegacyParams $p) => $this->activeReceivers((int) $p->get('idMasterUser')),
            'get_receiver_by_id' => fn (LegacyParams $p) => $this->receiverById((int) $p->get('idMasterUser'), (string) $p->get('idReceptor')),
            'add_companny_reciver' => fn (LegacyParams $p) => $this->addReceiver($p),
            'delete_reciver' => fn (LegacyParams $p) => $this->deleteReceiver($p),

            // --- Comprobantes / consecutivos ---
            'get_vouchers' => fn (LegacyParams $p) => $this->vouchers((int) $p->get('idMasterUser'), (string) $p->get('env')),
            'companny_getMyConsecutive' => fn (LegacyParams $p) => $this->myConsecutive($p),

            // --- Inventario ---
            'get_inventory' => fn (LegacyParams $p) => $this->inventory((int) $p->get('idMasterUser'), (string) $p->get('sucursal')),
            'getProductByCode' => fn (LegacyParams $p) => $this->productByCode($p),
            'addInventaryProduct' => fn (LegacyParams $p) => $this->addProduct($p),
            'get_tipo_impuesto' => fn () => $this->taxTypes(),
            'getUnid' => fn () => $this->measureUnits(),
        ];
    }

    // ---- Catálogos ----

    /**
     * DISTINCT preservando el orden de inserción del dump legacy (MySQL lo
     * devolvía en orden físico). Se agrupa por las columnas proyectadas y se
     * ordena por el id mínimo del grupo.
     *
     * @param  array<string, string>  $select  alias => columna
     * @param  array<string, string>  $where  columna => valor
     */
    private function distinctInInsertionOrder(array $select, array $where): array
    {
        $q = DB::table('codificacion_mh');
        foreach ($where as $col => $val) {
            $q->where($col, $val);
        }

        $columns = array_values($select);
        $aliased = [];
        foreach ($select as $alias => $col) {
            $aliased[] = "$col as $alias";
        }

        return $q->groupBy($columns)
            ->orderByRaw('MIN(id)')
            ->get($aliased)->all();
    }

    private function provinces(): array
    {
        return $this->distinctInInsertionOrder(
            ['idProvincia' => 'id_provincia', 'nombreProvincia' => 'nombre_provincia'],
            []
        );
    }

    private function cantons(string $idProvincia): array
    {
        return $this->distinctInInsertionOrder(
            ['nombreCanton' => 'nombre_canton', 'idCanton' => 'id_canton'],
            ['id_provincia' => $idProvincia]
        );
    }

    private function districts(string $idProvincia, string $idCanton): array
    {
        return $this->distinctInInsertionOrder(
            ['nombreDistrito' => 'nombre_distrito', 'idDistrito' => 'id_distrito'],
            ['id_provincia' => $idProvincia, 'id_canton' => $idCanton]
        );
    }

    private function neighborhoods(string $idProvincia, string $idCanton, string $idDistrito): array
    {
        return $this->distinctInInsertionOrder(
            ['nombreBarrio' => 'nombre_barrio', 'idBarrio' => 'id_barrio'],
            ['id_provincia' => $idProvincia, 'id_canton' => $idCanton, 'id_distrito' => $idDistrito]
        );
    }

    private function typeOfId(): array
    {
        return DB::table('tipo_cedula')->select('codigo', 'descripcion')->get()->all();
    }

    private function taxTypes(): array
    {
        return DB::table('tipo_impuestos')->get()->all();
    }

    private function measureUnits(): array
    {
        return DB::table('unidad_medida')->orderBy('id')->get()->all();
    }

    private function locationInfo(LegacyParams $p): array
    {
        return DB::table('codificacion_mh')
            ->where('id_provincia', (string) $p->get('idProvincia'))
            ->where('id_canton', (string) $p->get('idCanton'))
            ->where('id_distrito', (string) $p->get('idDistrito'))
            ->where('id_barrio', (string) $p->get('idBarrio'))
            ->select(
                'nombre_provincia as nombreProvincia',
                'nombre_canton as nombreCanton',
                'nombre_distrito as nombreDistrito',
                'nombre_barrio as nombreBarrio',
            )->get()->all();
    }

    // ---- Autenticación de sub-usuarios ----

    private function companyLogin(LegacyParams $p): array|string
    {
        $companyId = (int) $p->get('idMasterUser');
        $user = $this->companyAuth->loadByUserNameOrEmail($companyId, (string) $p->get('userName', ''));

        if ($user === null || ! $this->companyAuth->verifyPassword($user, (string) $p->get('pwd', ''))) {
            return '-301';
        }

        return [
            'sessionKey' => $this->companyAuth->generateSessionKey($user, request()),
            'userName' => $user->user_name,
            'idUser' => (string) $user->id,
        ];
    }

    private function companyLogout(LegacyParams $p): string
    {
        $companyId = (int) $p->get('idMasterUser');
        CompanyUserSession::where('company_id', $companyId)
            ->where('session_key', (string) $p->get('sessionKey', ''))
            ->where('ip', (string) request()->ip())
            ->delete();

        return 'good bye';
    }

    private function companyMyDetails(LegacyParams $p): array|int
    {
        $companyId = (int) $p->get('idMasterUser');
        $user = $this->companyAuth->loadByUserNameOrEmail($companyId, (string) $p->get('iam', ''));
        if ($user === null) {
            return -1;
        }

        return [
            'idUser' => (string) $user->id,
            'fullName' => (string) $user->full_name,
            'userName' => (string) $user->user_name,
            'email' => (string) $user->email,
            'about' => (string) $user->about,
            'country' => (string) $user->country,
            'status' => (string) $user->status,
        ];
    }

    // ---- Info de empresa ----

    private function companyInformation(int $companyId): array
    {
        $c = Company::find($companyId);
        if ($c === null) {
            return [];
        }

        // Se reconstruye el shape EAV (name/value) que devolvía el legacy.
        $map = [
            'NOMBRE' => $c->nombre, 'NCODPAIS' => $c->tel_cod_pais, 'TIPOCAMBIO' => $c->tipo_cambio,
            'situacion' => $c->situacion, 'TIPOCED' => $c->tipo_cedula, 'CEDULA' => $c->cedula,
            'NOMCOMER' => $c->nombre_comercial, 'PROVINCIA' => $c->id_provincia, 'CANTON' => $c->id_canton,
            'DISTRITO' => $c->id_distrito, 'BARRIO' => $c->id_barrio, 'SENNAS' => $c->sennas,
            'NNUMER' => $c->tel_numero, 'FCODPAIS' => $c->fax_cod_pais, 'EMAIL' => $c->email,
            'FNUMER' => $c->fax_numero,
        ];

        $out = [];
        foreach ($map as $name => $value) {
            $out[] = (object) ['name' => $name, 'value' => (string) $value];
        }

        return $out;
    }

    private function companyGetEnv(int $companyId): array
    {
        $env = Company::where('id', $companyId)->value('env');

        return [(object) ['env' => (string) $env]];
    }

    // ---- Sucursales / terminales ----

    private function branches(LegacyParams $p): array
    {
        $companyId = $this->resolveCompanyId($p);

        return Branch::where('company_id', $companyId)
            ->get()
            ->map(fn ($b) => (object) [
                'idSucursal' => (string) $b->id,
                'nombreSucursal' => $b->nombre_sucursal,
                'sucursal' => $b->sucursal,
            ])->all();
    }

    private function terminals(LegacyParams $p): array
    {
        $companyId = $this->resolveCompanyId($p);
        $q = Terminal::where('terminals.company_id', $companyId)
            ->join('branches', 'terminals.branch_id', '=', 'branches.id');

        if (($idSucursal = $p->get('idSucursal', '')) !== '') {
            $q->where('branches.id', $idSucursal);
        }

        return $q->get([
            'terminals.id as idTerminal', 'terminals.nombre_terminal as nombreTerminal',
            'terminals.terminal', 'branches.nombre_sucursal as nombreSucursal', 'branches.sucursal',
        ])->all();
    }

    private function addBranch(LegacyParams $p): int|string
    {
        return $this->insertRow(fn () => Branch::create([
            'company_id' => $this->resolveCompanyId($p),
            'nombre_sucursal' => (string) $p->get('nombreSucursal'),
            'sucursal' => (string) $p->get('numeroSucursal'),
        ]));
    }

    private function addTerminal(LegacyParams $p): int|string
    {
        $companyId = $this->resolveCompanyId($p);

        return $this->insertRow(fn () => Terminal::create([
            'company_id' => $companyId,
            // El legacy no tiene FK: un idSucursal ajeno se acepta y la
            // terminal queda sin sucursal (getTerminales no la lista).
            'branch_id' => Branch::where('company_id', $companyId)->whereKey($p->get('idSucursal'))->value('id'),
            'nombre_terminal' => (string) $p->get('nombreTerminal'),
            'terminal' => (string) $p->get('numeroTerminal'),
        ]));
    }

    // ---- Receptores ----

    private function activeReceivers(int $companyId): array
    {
        return Receiver::where('company_id', $companyId)
            ->where('estado_cliente', '1')
            ->get()
            ->map(fn ($r) => (object) [
                'nombreCliente' => $r->nombre_cliente,
                'idReceptor' => (string) $r->id,
                'correoPrincipal' => $r->correo_principal,
                'telefono' => $r->telefono,
                'tipoCedula' => $r->tipo_cedula,
                'numeroCedula' => $r->numero_cedula,
            ])->all();
    }

    private function receiverById(int $companyId, string $idReceptor): array
    {
        return Receiver::where('company_id', $companyId)
            ->where('estado_cliente', '1')->where('id', $idReceptor)
            ->get()->all();
    }

    private function addReceiver(LegacyParams $p): int|string
    {
        $companyId = (int) $p->get('idMasterUser');

        return $this->insertRow(fn () => Receiver::create([
            'company_id' => $companyId,
            'company_user_id' => CompanyUserSession::where('company_id', $companyId)
                ->where('session_key', (string) $p->get('sessionKey'))
                ->value('company_user_id'),
            'nombre_cliente' => (string) $p->get('nombreCliente'),
            'numero_cedula' => (string) $p->get('numeroCedula'),
            'tipo_cedula' => (string) $p->get('tipoCedula'),
            'telefono' => (string) $p->get('telefono'),
            'id_provincia' => (string) $p->get('idProvincia'),
            'id_canton' => (string) $p->get('idCanton'),
            'id_distrito' => (string) $p->get('idDistrito'),
            'id_barrio' => (string) $p->get('idBarrio'),
            'otras_senas' => (string) $p->get('otrasSenas'),
            'nombre_comercial' => (string) $p->get('nombreComercial'),
            'correo_principal' => (string) $p->get('correoPrincipal'),
            'copias_correo' => (string) $p->get('copiasCorreo'),
            'numero_fax' => (string) $p->get('numeroFax'),
        ]));
    }

    /** Borrado lógico. Como affected_rows de MySQL, un receptor ya inactivo no cuenta. */
    private function deleteReceiver(LegacyParams $p): int|string
    {
        $updated = Receiver::where('company_id', $this->resolveCompanyId($p))
            ->whereKey($p->get('idReceptor'))
            ->where('estado_cliente', '!=', '0')
            ->update(['estado_cliente' => '0']);

        return $updated > 0 ? $updated : LegacyErrorCode::DB_NO_RESULTS_FOUND;
    }

    // ---- Comprobantes / consecutivos ----

    private function vouchers(int $companyId, string $env): array
    {
        return Document::where('company_id', $companyId)->where('env', $env)
            ->get()
            ->map(fn ($d) => (object) [
                'idComprobante' => (string) $d->id,
                'consecutivo' => $d->consecutivo,
                'clave' => $d->clave,
                'tipoDocumento' => $d->tipo_documento,
                'estado' => $d->estado,
                'fechaCreacion' => (string) $d->fecha_creacion,
            ])->all();
    }

    private function myConsecutive(LegacyParams $p): array
    {
        $companyId = (int) $p->get('idMasterUser');
        $value = Consecutive::where('consecutives.company_id', $companyId)
            ->join('company_users', 'consecutives.company_user_id', '=', 'company_users.id')
            ->where('company_users.user_name', (string) $p->get('iam'))
            ->where('consecutives.env', (string) $p->get('env'))
            ->where('consecutives.tipo_comprobante', (string) $p->get('tipoComprobante'))
            ->max('consecutives.numero_consecutivo') ?? 0;

        return [(object) ['consecutivo' => (string) $value]];
    }

    // ---- Inventario ----

    private function inventory(int $companyId, string $sucursal): array
    {
        return Product::where('company_id', $companyId)->where('sucursal', $sucursal)
            ->get()
            ->map(fn ($p) => (object) [
                'idProducto' => (string) $p->id,
                'codigoBarras' => $p->codigo_barras,
                'nombre' => $p->nombre,
                'unidadMedida' => $p->unidad_medida,
                'precioVenta' => (string) $p->precio_venta,
                'disponible' => (string) $p->disponible,
            ])->all();
    }

    private function productByCode(LegacyParams $p): array
    {
        return Product::where('products.company_id', (int) $p->get('idMasterUser'))
            ->where('products.sucursal', (string) $p->get('sucursal'))
            ->where('products.codigo_barras', (string) $p->get('codigo'))
            ->leftJoin('tipo_impuestos', 'products.id_impuesto', '=', 'tipo_impuestos.id')
            ->get([
                'products.id as idProducto', 'products.descripcion', 'products.unidad_medida as unidadMedida',
                'products.precio_venta as precioVenta', 'products.cantidad_impuesto as cantidadImpuesto',
                'tipo_impuestos.codigo',
            ])->all();
    }

    /** Los valores numéricos van como llegan: igual que el legacy, la BD decide si los acepta. */
    private function addProduct(LegacyParams $p): int|string
    {
        return $this->insertRow(fn () => Product::create([
            'company_id' => (int) $p->get('idMasterUser'),
            'sucursal' => (string) $p->get('sucursal'),
            'nombre' => (string) $p->get('nombre'),
            'descripcion' => (string) $p->get('descripcion'),
            'unidad_medida' => (string) $p->get('unidadMedida'),
            'precio_venta' => $p->get('precioVenta'),
            'id_impuesto' => $p->get('idImpuesto'),
            'cantidad_impuesto' => $p->get('cantidadImpuesto'),
            'codigo_barras' => (string) $p->get('codigoBarras'),
            'disponible' => $p->get('disponible'),
        ]));
    }

    /** INSERT con la respuesta de db_query() legacy: affected_rows o ERROR_DB_ERROR. */
    private function insertRow(\Closure $insert): int|string
    {
        try {
            $insert();
        } catch (QueryException) {
            return LegacyErrorCode::DB_ERROR;
        }

        return 1;
    }

    private function resolveCompanyId(LegacyParams $p): int
    {
        $id = (int) $p->get('idMasterUser', 0);
        if ($id > 0) {
            return $id;
        }

        // El master user opera sobre su propia empresa (company_id == user id).
        $sessionKey = (string) $p->get('sessionKey', '');

        return (int) LegacySession::where('session_key', $sessionKey)->value('user_id');
    }
}
