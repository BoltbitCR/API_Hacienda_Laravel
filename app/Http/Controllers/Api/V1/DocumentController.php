<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmitDocumentRequest;
use App\Http\Resources\DocumentDetailResource;
use App\Http\Resources\DocumentResource;
use App\Http\Support\ActingCompany;
use App\Models\Company;
use App\Models\Document;
use App\Models\HaciendaCredential;
use App\Services\Emission\DocumentEmissionService;
use App\Services\Emission\DuplicateDocumentException;
use App\Services\Emission\EmissionException;
use App\Services\Hacienda\ComprobantesClient;
use App\Services\Hacienda\HaciendaUnavailableException;
use App\Services\Hacienda\StatusClient;
use App\Services\Hacienda\TokenService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Emisión y seguimiento de comprobantes electrónicos (recurso principal de
 * la API v1). El emisor es la empresa del token; el flujo completo
 * (clave → XML → firma → token → envío → persistencia) lo ejecuta
 * DocumentEmissionService.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentEmissionService $emission,
        private readonly StatusClient $status,
        private readonly TokenService $tokens,
    ) {}

    /**
     * Lista los comprobantes de la empresa autenticada.
     */
    public function index(Request $request): JsonResponse
    {
        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');

        $docs = Document::where('company_id', $company->id)
            ->when($request->query('environment'), fn ($q, $env) => $q->where('env', $env === 'prod' ? 'api-prod' : 'api-stag'))
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('tipo_documento', $t))
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 25));

        return DocumentResource::collection($docs)->response();
    }

    /**
     * Emite un nuevo comprobante electrónico.
     *
     * El código refleja lo que respondió la recepción de Hacienda: 201 si
     * lo recibió (202); 422 si lo rechazó (400, causa en `recepcion.causa`);
     * 502 si no lo recibió (sin conexión o 5xx) — el comprobante queda en
     * `estado: error` y se puede reintentar con el mismo `consecutivo`.
     *
     * Con el header `Idempotency-Key` (hasta 100 caracteres), repetir la
     * petición nunca emite otro comprobante: si Hacienda ya lo recibió o lo
     * rechazó, devuelve lo emitido con el mismo criterio (200 en vez de
     * 201); si no lo recibió, reenvía el mismo XML firmado (misma clave y
     * consecutivo, `reenviado: true`), después de consultar la clave en
     * Hacienda: si ya la tiene no reenvía y responde 201 con
     * `ya_recibido: true`; si no se pudo consultar, 502 con
     * `sin_confirmar: true` sin reenviar. Si falló antes de firmarse, lo vuelve
     * a emitir con el mismo consecutivo. Con una emisión en curso con esa
     * clave, 409. Un consecutivo que ya llegó a Hacienda responde 409.
     *
     * Si el IdP de Hacienda no responde, 503 con `Retry-After` sin consumir
     * consecutivo; credenciales rechazadas por el IdP, 422.
     *
     * @throws HaciendaUnavailableException
     */
    public function store(EmitDocumentRequest $request): JsonResponse
    {
        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');

        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey === null) {
            return $this->emitir($request, $company);
        }

        // Una petición a la vez por clave: dos reintentos simultáneos no
        // pueden emitir ni reenviar dos veces.
        $lock = Cache::lock('documents:idempotency:'.$company->id.':'.hash('sha256', $idempotencyKey), 180);
        if (! $lock->get()) {
            return response()->json(['message' => "Ya hay una emisión en curso con la Idempotency-Key '$idempotencyKey'."], 409);
        }

        try {
            $previo = Document::where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first();

            return $previo === null
                ? $this->emitir($request, $company, $idempotencyKey)
                : $this->repetir($request, $company, $previo, $idempotencyKey);
        } finally {
            $lock->release();
        }
    }

    private function emitir(EmitDocumentRequest $request, Company $company, ?string $idempotencyKey = null, ?Document $reintentoDe = null): JsonResponse
    {
        try {
            $result = $this->emission->emit(
                company: $company,
                tipo: $request->string('tipo')->value(),
                documentParams: $request->documentParams(),
                environment: $this->environment($request, $company),
                idempotencyKey: $idempotencyKey,
                reintentoDe: $reintentoDe,
            );
        } catch (DuplicateDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'document_id' => $e->documentId], 409);
        } catch (EmissionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respuestaEmision($result, $result['recepcion']['status'], $result['estado']);
    }

    /**
     * Petición repetida con la misma Idempotency-Key. Lo que Hacienda ya
     * recibió o rechazó se devuelve sin tocarla; lo que no recibió se
     * reenvía (mismo XML firmado, después de confirmar con Hacienda que no
     * tiene la clave) o se vuelve a emitir con el mismo consecutivo si nunca
     * llegó a firmarse.
     */
    private function repetir(EmitDocumentRequest $request, Company $company, Document $previo, string $idempotencyKey): JsonResponse
    {
        $recepcion = $previo->recepcion_status === null ? null : (int) $previo->recepcion_status;

        if ($recepcion === 202 || $recepcion === 400) {
            return $this->respuestaEmision($this->replay($previo), $recepcion, $previo->estado, replay: true);
        }

        if ($previo->estado === DocumentEmissionService::ESTADO_GENERANDO && Carbon::parse($previo->fecha_creacion)->gt(now()->subMinutes(10))) {
            return response()->json(['message' => "Ya hay una emisión en curso con la Idempotency-Key '$idempotencyKey'.", 'document_id' => $previo->id], 409);
        }

        if (filled($previo->xml_enviado_base64)) {
            try {
                $result = $this->emission->reenviar($company, $previo, $request->documentParams());
            } catch (EmissionException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            if ($result['sin_confirmar'] ?? false) {
                return response()->json([
                    'message' => 'No se pudo confirmar con Hacienda si ya recibió el comprobante ('.$result['recepcion']['causa'].'); no se reenvió. Reintentar con la misma Idempotency-Key.',
                    ...$result,
                ], 502);
            }

            return $this->respuestaEmision($result, $result['recepcion']['status'], $result['estado']);
        }

        return $this->emitir($request, $company, $idempotencyKey, reintentoDe: $previo);
    }

    /**
     * Respuesta de una emisión según la recepción: 201 (200 en un replay) si
     * Hacienda lo recibió, 422 si lo rechazó o falló antes de enviarse y
     * 502 si no lo recibió. Los errores llevan `message` además del cuerpo.
     *
     * @param  array<string, mixed>  $body
     */
    private function respuestaEmision(array $body, int|string|null $recepcion, ?string $estado, bool $replay = false): JsonResponse
    {
        $recepcion = $recepcion === null ? null : (int) $recepcion;
        $causa = $body['recepcion']['causa'] ?? null;

        return match (true) {
            $recepcion === 202, $recepcion === null && $estado !== DocumentEmissionService::ESTADO_ERROR => $replay
                ? response()->json($body, 200)
                : response()->json($body, 201),
            $recepcion === 400 => response()->json(['message' => $causa ?? 'Hacienda rechazó el comprobante en la recepción.', ...$body], 422),
            // Sin recepción y en error: falló antes de enviarse, como la emisión original.
            $recepcion === null => response()->json(['message' => $causa ?? 'La emisión falló antes de enviarse a Hacienda.', ...$body], 422),
            default => response()->json(['message' => 'Hacienda no recibió el comprobante'.($causa ? " ($causa)" : '').'; se puede reintentar con el mismo consecutivo.', ...$body], 502),
        };
    }

    /**
     * Genera y firma el comprobante y lo valida contra el XSD oficial v4.4
     * sin consumir consecutivo ni enviarlo a Hacienda. Mismo cuerpo que la
     * emisión; responde `valid`, `errors` y el XML sin firmar.
     */
    public function validate(EmitDocumentRequest $request): JsonResponse
    {
        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');

        try {
            $result = $this->emission->validateOnly(
                company: $company,
                tipo: $request->string('tipo')->value(),
                documentParams: $request->documentParams(),
                environment: $this->environment($request, $company),
            );
        } catch (EmissionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * Muestra un comprobante de la empresa, con el XML enviado y la
     * respuesta de Hacienda (MensajeHacienda) si ya se consultó.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany($request, $document);

        return DocumentDetailResource::make($document)->response();
    }

    /**
     * Consulta el estado del comprobante en Hacienda y lo actualiza. Guarda
     * también el MensajeHacienda (`respuesta-xml`), que es el respaldo
     * fiscal del aceptado o rechazado.
     *
     * 502 si no se pudo consultar Hacienda, 404 si Hacienda no tiene la
     * clave (nunca recibió el comprobante) y 503 si el IdP no responde; en
     * esos casos el estado guardado no cambia.
     *
     * @throws HaciendaUnavailableException
     */
    public function status(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany($request, $document);

        $company = ActingCompany::for($request->user());
        $environment = $document->env === 'api-prod' ? 'prod' : 'stag';
        $credential = $company->credentials()->where('environment', $environment)->first();
        abort_if($credential === null, 422, 'Sin credenciales para consultar.');

        $token = $this->tokenFor($credential, $document->env);
        abort_if($token === null, 422, 'Las credenciales guardadas no autenticaron contra Hacienda.');

        // 502: no se pudo consultar (sin conexión, 5xx, otro error de Hacienda);
        // 404: Hacienda no tiene la clave. Ninguno es un estado del comprobante.
        try {
            $result = $this->status->estado($document->clave, $document->env, $token);
        } catch (RuntimeException $e) {
            abort(502, $e->getMessage());
        }
        abort_if($result === null, 404, 'Hacienda no tiene información para esa clave.');

        if (isset($result->{'ind-estado'})) {
            $cambios = ['estado' => $result->{'ind-estado'}];
            if (is_string($result->{'respuesta-xml'} ?? null) && $result->{'respuesta-xml'} !== '') {
                $cambios['respuesta_mh_base64'] = $result->{'respuesta-xml'};
            }
            $document->update($cambios);
        }

        return response()->json(['document' => DocumentDetailResource::make($document), 'hacienda' => $result]);
    }

    private function authorizeCompany(Request $request, Document $document): void
    {
        $company = ActingCompany::for($request->user());
        abort_if($company === null || $document->company_id !== $company->id, 403, 'No autorizado.');
    }

    /**
     * Estado en Hacienda de CUALQUIER comprobante, dado su clave.
     *
     * A diferencia de status(), no exige que el comprobante exista acá:
     * un receptor necesita poder verificar lo que le emitió su proveedor,
     * y Hacienda responde la consulta a cualquier contribuyente con token
     * válido. Se usan las credenciales ATV de la empresa autenticada.
     *
     * Sin `environment` se usa producción si la empresa tiene esas
     * credenciales; si no, las de staging.
     *
     * @throws HaciendaUnavailableException
     */
    public function statusByClave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clave' => ['required', 'string', 'regex:/^[A-Za-z0-9]{50}$/'],
            'environment' => ['nullable', 'in:prod,stag'],
        ], [
            'clave.regex' => 'La clave debe tener exactamente 50 caracteres alfanuméricos.',
        ]);

        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');

        [$credential, $clientId, $token] = $this->haciendaSession($company, $data['environment'] ?? null);

        // 502: no se pudo consultar (sin conexión, 5xx, otro error de Hacienda);
        // 404: Hacienda no tiene la clave. Ninguno es un estado del comprobante.
        try {
            $result = $this->status->estado($data['clave'], $clientId, $token);
        } catch (RuntimeException $e) {
            abort(502, $e->getMessage());
        }
        abort_if($result === null, 404, 'Hacienda no tiene información para esa clave.');

        return response()->json([
            'clave' => $data['clave'],
            'environment' => $credential->environment,
            'hacienda' => $result,
        ]);
    }

    /**
     * Comprobantes que Hacienda tiene registrados con la empresa como
     * emisor, de todas las páginas. Sirve para saber qué consecutivos ya se
     * usaron, también los emitidos con otro sistema.
     */
    public function comprobantes(Request $request, ComprobantesClient $client): JsonResponse
    {
        $data = $request->validate(['environment' => ['nullable', 'in:prod,stag']]);

        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');
        abort_if(! ctype_digit((string) $company->tipo_cedula) || preg_replace('/\D/', '', (string) $company->cedula) === '',
            422, 'La empresa no tiene tipo y número de identificación.');

        [$credential, $clientId, $token] = $this->haciendaSession($company, $data['environment'] ?? null);
        $emisor = ComprobantesClient::emisor((string) $company->tipo_cedula, (string) $company->cedula);

        try {
            $comprobantes = $client->listar($emisor, $clientId, $token);
        } catch (RuntimeException|ConnectionException $e) {
            abort(502, $e->getMessage());
        }

        return response()->json([
            'environment' => $credential->environment,
            'emisor' => $emisor,
            'total' => count($comprobantes),
            'comprobantes' => $comprobantes,
        ]);
    }

    private function environment(Request $request, Company $company): string
    {
        return $request->string('environment')->value() ?: ($company->env === 'api-prod' ? 'prod' : 'stag');
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        abort_if(mb_strlen($key) > 100, 422, 'Idempotency-Key admite como máximo 100 caracteres.');

        return $key !== '' ? $key : null;
    }

    /**
     * Respuesta de una emisión repetida con la misma Idempotency-Key: lo que
     * ya se emitió, sin tocar Hacienda ni la numeración.
     *
     * @return array<string, mixed>
     */
    private function replay(Document $document): array
    {
        return [
            'clave' => $document->clave,
            'consecutivo' => $document->consecutivo,
            'xmlFirmado' => $document->xml_enviado_base64,
            'document_id' => $document->id,
            'estado' => $document->estado,
            'recepcion' => ['status' => $document->recepcion_status, 'causa' => $document->mensaje_error],
            'idempotent_replay' => true,
        ];
    }

    /**
     * Credencial del ambiente pedido (o prod antes que stag), su client_id y
     * un token vigente.
     *
     * @return array{0: HaciendaCredential, 1: string, 2: string}
     */
    private function haciendaSession(Company $company, ?string $environment): array
    {
        $credential = $company->credentials()
            ->when($environment !== null, fn ($q) => $q->where('environment', $environment))
            ->orderByRaw("case when environment = 'prod' then 0 else 1 end")
            ->first();

        abort_if($credential === null, 422, 'Sin credenciales para consultar.');

        $clientId = $credential->environment === 'prod' ? 'api-prod' : 'api-stag';
        $token = $this->tokenFor($credential, $clientId);

        abort_if($token === null, 422, 'Las credenciales guardadas no autenticaron contra Hacienda.');

        return [$credential, $clientId, $token];
    }

    private function tokenFor(HaciendaCredential $credential, string $clientId): ?string
    {
        return $this->tokens->accessToken($clientId, (string) $credential->username, (string) $credential->password);
    }
}
