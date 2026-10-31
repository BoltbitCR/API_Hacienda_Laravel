<?php

namespace App\Services\Emission;

use App\Legacy\LegacyParams;
use App\Models\Company;
use App\Models\Consecutive;
use App\Models\Document;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Services\Clave\ClaveService;
use App\Services\Files\FileStorageService;
use App\Services\Hacienda\ReceptionClient;
use App\Services\Hacienda\TokenService;
use App\Services\Signature\XadesSignerService;
use App\Services\Xml\Legacy\CurrentParams;
use App\Services\Xml\Legacy\LegacyReplyKill;
use App\Services\Xsd\XsdValidatorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta la emisión completa de un comprobante electrónico para una
 * empresa: clave → XML (generador v4.4 verbatim) → firma XAdES → token
 * Hacienda → envío → persistencia. Es la implementación real del flujo
 * "todo en uno" que el módulo legacy crlibreall dejó como stub.
 *
 * Reutiliza los mismos Services que la capa de compatibilidad, de modo que
 * el XML emitido es byte-idéntico al del API legacy para los mismos datos.
 *
 * Orden de los pasos: todo lo que puede fallar sin depender del comprobante
 * (credenciales, certificado, token del IdP) va ANTES de consumir el
 * consecutivo, para no dejar huecos en la numeración por errores evitables.
 * Cada intento queda registrado en `documents` desde que se reserva la clave.
 */
class DocumentEmissionService
{
    /** tipoDocumento (input REST) => función generadora verbatim. */
    private const GENERATORS = [
        'FE' => 'genXMLFe',
        'NC' => 'genXMLNC',
        'ND' => 'genXMLND',
        'TE' => 'genXMLTE',
        'FEC' => 'genXMLFec',
        'FEE' => 'genXMLFee',
        'REP' => 'genXMLRep',
        'MR' => 'genXMLMr',
    ];

    /** Mensaje receptor (nota 11) => tipo de confirmación de la clave (nota 3). */
    public const MR_TIPOS = ['1' => 'CCE', '2' => 'CPCE', '3' => 'RCE'];

    /** Estados de `documents.estado` que escribe este servicio. */
    public const ESTADO_GENERANDO = 'generando';

    public const ESTADO_ENVIADO = 'enviado';

    public const ESTADO_RECHAZADO = 'rechazado';

    public const ESTADO_ERROR = 'error';

    public function __construct(
        private readonly ClaveService $clave,
        private readonly XadesSignerService $signer,
        private readonly TokenService $tokens,
        private readonly ReceptionClient $reception,
        private readonly XsdValidatorService $xsd,
        private readonly FileStorageService $files,
    ) {}

    public static function supportedTypes(): array
    {
        return array_keys(self::GENERATORS);
    }

    /**
     * Emite un comprobante. $documentParams usa las mismas claves que el
     * generador legacy (detalles, medios_pago, totales, etc.); los datos del
     * emisor y el consecutivo se derivan de la empresa.
     *
     * @param  array<string, mixed>  $documentParams
     * @return array{clave: string, consecutivo: string, xml: string, xmlFirmado: string, envio: array, document_id: int, estado: string, recepcion: array{status: int, causa: ?string}}
     *
     * @throws EmissionException
     */
    public function emit(Company $company, string $tipo, array $documentParams, string $environment, ?string $idempotencyKey = null): array
    {
        $this->assertSupported($tipo);

        $clientId = $environment === 'prod' ? 'api-prod' : 'api-stag';
        $credential = $this->credential($company, $environment);

        // Lo que no depende del comprobante se resuelve antes de tocar la
        // numeración: certificado presente y token del IdP.
        $p12 = $this->p12($credential);
        $accessToken = $this->tokens->accessToken($clientId, (string) $credential->username, (string) $credential->password);
        if ($accessToken === null) {
            throw new EmissionException('No se pudo obtener el token de Hacienda.');
        }

        $documentParams['fecha_emision'] ??= now()->format('Y-m-d\TH:i:sP');

        // 1. Consecutivo, clave y registro del intento (una sola transacción
        //    con la serie de la empresa bloqueada: dos peticiones iguales no
        //    producen dos comprobantes).
        [$document, $claveResult] = $this->reservar($company, $tipo, $documentParams, $clientId, $idempotencyKey);

        try {
            // 2. XML (generador verbatim) con emisor de la empresa + datos del cliente.
            $xml = $this->buildXml($company, $tipo, $documentParams, $document->clave, $claveResult['consecutivo']);

            // 3. Firma XAdES con el .p12 de la empresa.
            $xmlFirmado = $this->sign($p12, (string) $credential->pin, $xml);

            // 4. XSD antes de enviar (opcional): un rechazo seguro no viaja a Hacienda.
            if (config('hacienda.xsd_before_send')) {
                $this->assertXsd($xmlFirmado, $tipo);
            }
        } catch (EmissionException $e) {
            $document->update(['estado' => self::ESTADO_ERROR, 'mensaje_error' => $e->getMessage()]);

            throw $e;
        } finally {
            $this->files->discardTemp($p12);
        }

        // 5. Envío a recepción.
        $envio = $this->reception->send(
            $this->payloadRecepcion($company, $tipo, $documentParams, $document, $claveResult['consecutivo'], $xmlFirmado),
            $clientId,
            $accessToken,
        );

        // 6. Persistencia: el estado refleja lo que respondió Hacienda.
        [$estado, $causa] = self::estadoRecepcion($envio);
        $document->update([
            'estado' => $estado,
            'recepcion_status' => (int) ($envio['Status'] ?? 0),
            'mensaje_error' => $causa,
            'xml_enviado_base64' => base64_encode($xmlFirmado),
        ]);

        return [
            'clave' => $document->clave,
            'consecutivo' => $claveResult['consecutivo'],
            'xml' => base64_encode($xml),
            'xmlFirmado' => base64_encode($xmlFirmado),
            'envio' => $envio,
            'document_id' => $document->id,
            'estado' => $estado,
            'recepcion' => ['status' => (int) ($envio['Status'] ?? 0), 'causa' => $causa],
        ];
    }

    /**
     * Genera y firma el comprobante con una clave provisional y lo valida
     * contra el XSD oficial, sin consumir consecutivo ni enviar nada.
     *
     * @param  array<string, mixed>  $documentParams
     * @return array{valid: bool, errors: list<string>, xml: string}
     *
     * @throws EmissionException
     */
    public function validateOnly(Company $company, string $tipo, array $documentParams, string $environment): array
    {
        $this->assertSupported($tipo);

        $credential = $this->credential($company, $environment);
        $p12 = $this->p12($credential);
        $documentParams['fecha_emision'] ??= now()->format('Y-m-d\TH:i:sP');

        $claveResult = $this->claveResult($company, $tipo, $documentParams, $documentParams['consecutivo'] ?? '0000000000');
        $clave = $tipo === 'MR' ? (string) $documentParams['clave'] : $claveResult['clave'];

        try {
            $xml = $this->buildXml($company, $tipo, $documentParams, $clave, $claveResult['consecutivo']);
            $xmlFirmado = $this->sign($p12, (string) $credential->pin, $xml);
        } finally {
            $this->files->discardTemp($p12);
        }

        $resultado = $this->xsd->validate($xmlFirmado, $this->tipoXsd($tipo, $documentParams));

        return ['valid' => $resultado['valid'], 'errors' => $resultado['errors'], 'xml' => base64_encode($xml)];
    }

    /**
     * Mapea la respuesta de la recepción a un estado y una causa legible.
     *
     * @param  array{Status?: mixed, text?: mixed}  $envio
     * @return array{0: string, 1: ?string}
     */
    public static function estadoRecepcion(array $envio): array
    {
        $status = (int) ($envio['Status'] ?? 0);
        $causa = self::causaRecepcion($envio);

        return match (true) {
            $status === 202 => [self::ESTADO_ENVIADO, null],
            $status === 400 => [self::ESTADO_RECHAZADO, $causa ?? 'Hacienda rechazó el comprobante en la recepción (HTTP 400).'],
            $status === 0 => [self::ESTADO_ERROR, 'No se pudo conectar con Hacienda'.(is_string($envio['text'] ?? null) && $envio['text'] !== '' ? ': '.$envio['text'] : '.')],
            default => [self::ESTADO_ERROR, "Hacienda respondió HTTP $status en la recepción".($causa ? ': '.$causa : '.')],
        };
    }

    /** Header X-Error-Cause (o el cuerpo) de la respuesta cruda de Hacienda. */
    private static function causaRecepcion(array $envio): ?string
    {
        $lineas = $envio['text'] ?? null;
        if (! is_array($lineas)) {
            return null;
        }

        foreach ($lineas as $linea) {
            if (is_string($linea) && stripos($linea, 'x-error-cause:') === 0) {
                return trim(substr($linea, strlen('x-error-cause:'))) ?: null;
            }
        }

        $body = trim((string) end($lineas));

        return $body !== '' && $body !== 'HTTP/1.1 400 ' ? mb_substr($body, 0, 500) : null;
    }

    private function assertSupported(string $tipo): void
    {
        if (! isset(self::GENERATORS[$tipo])) {
            throw new EmissionException("Tipo de documento no soportado: $tipo");
        }
    }

    /**
     * Reserva consecutivo y clave y registra el intento, con la serie de la
     * empresa bloqueada. Rechaza un consecutivo que ya llegó a Hacienda.
     *
     * @return array{0: Document, 1: array{clave: string, consecutivo: string}}
     */
    private function reservar(Company $company, string $tipo, array $documentParams, string $clientId, ?string $idempotencyKey): array
    {
        try {
            return DB::transaction(function () use ($company, $tipo, $documentParams, $clientId, $idempotencyKey) {
                $serie = $this->serie($company, $this->tipoSerie($tipo, $documentParams), $clientId);

                $consecutivo = $documentParams['consecutivo'] ?? $this->siguiente($serie);
                $claveResult = $this->claveResult($company, $tipo, $documentParams, (string) $consecutivo);
                $clave = $tipo === 'MR' ? (string) $documentParams['clave'] : $claveResult['clave'];

                $this->assertNoDuplicado($company, $claveResult['consecutivo'], $tipo, $clientId);

                $document = Document::create([
                    'company_id' => $company->id,
                    'consecutivo' => $claveResult['consecutivo'],
                    'clave' => $clave,
                    'tipo_documento' => $tipo,
                    'estado' => self::ESTADO_GENERANDO,
                    'env' => $clientId,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return [$document, $claveResult];
            });
        } catch (QueryException $e) {
            // Carrera con la misma Idempotency-Key: la otra petición ganó.
            if ($idempotencyKey !== null && str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new DuplicateDocumentException("Ya hay una emisión en curso con la Idempotency-Key '$idempotencyKey'.");
            }

            throw $e;
        }
    }

    /** Serie de numeración de la empresa para el tipo y ambiente (se crea si no existe), bloqueada. */
    private function serie(Company $company, string $tipoSerie, string $clientId): Consecutive
    {
        $row = Consecutive::where('company_id', $company->id)
            ->where('env', $clientId)
            ->where('tipo_comprobante', $tipoSerie)
            ->lockForUpdate()
            ->first();

        return $row ?? Consecutive::create([
            'company_id' => $company->id,
            'env' => $clientId,
            'tipo_comprobante' => $tipoSerie,
            'numero_consecutivo' => 0,
            'company_name' => $company->nombre,
        ]);
    }

    /** Incrementa y devuelve el correlativo de 10 dígitos. */
    private function siguiente(Consecutive $serie): string
    {
        $serie->increment('numero_consecutivo');

        return str_pad((string) $serie->numero_consecutivo, 10, '0', STR_PAD_LEFT);
    }

    /** Las confirmaciones del receptor llevan una serie por tipo (05/06/07), no una sola "MR". */
    private function tipoSerie(string $tipo, array $documentParams): string
    {
        return $tipo === 'MR' ? $this->tipoMr($documentParams) : $tipo;
    }

    private function tipoMr(array $documentParams): string
    {
        $tipo = self::MR_TIPOS[(string) ($documentParams['mensaje'] ?? '')] ?? null;
        if ($tipo === null) {
            throw new EmissionException('El mensaje receptor debe indicar mensaje 1 (aceptado), 2 (aceptación parcial) o 3 (rechazado).');
        }

        return $tipo;
    }

    /** @return array{clave: string, consecutivo: string} */
    private function claveResult(Company $company, string $tipo, array $documentParams, string $consecutivo): array
    {
        $result = $this->clave->generate(
            tipoDocumento: $tipo === 'MR' ? $this->tipoMr($documentParams) : $tipo,
            tipoCedula: (string) $company->tipo_cedula,
            cedula: $this->cedulaEmisor($company),
            situacion: (string) ($documentParams['situacion'] ?? ($company->situacion ?: 'normal')),
            codigoPais: '506',
            consecutivo: substr($consecutivo, -10),
            codigoSeguridad: str_pad((string) ($documentParams['codigoSeguridad'] ?? self::codigoSeguridadAleatorio()), 8, '0', STR_PAD_LEFT),
            sucursal: (string) ($documentParams['sucursal'] ?? '001'),
            terminal: (string) ($documentParams['terminal'] ?? '00001'),
            fecha: $this->fechaEmision($documentParams['fecha_emision'] ?? null),
        );

        if (is_string($result)) {
            throw new EmissionException('Error generando la clave: '.$result);
        }

        return $result;
    }

    /** El código de seguridad lo genera el emisor (nota 3, inciso h): 8 dígitos aleatorios. */
    public static function codigoSeguridadAleatorio(): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * La fecha de la clave es la de FechaEmision (deben coincidir, Anexos
     * pág. 19), en la zona del comprobante.
     */
    private function fechaEmision(?string $fechaEmision): CarbonImmutable
    {
        try {
            return $fechaEmision ? CarbonImmutable::parse($fechaEmision)->setTimezone(config('app.timezone')) : CarbonImmutable::now();
        } catch (\Throwable) {
            throw new EmissionException('fecha_emision no es una fecha válida (ISO 8601).');
        }
    }

    /**
     * Un consecutivo que ya llegó a Hacienda (recepción 202, o un registro
     * anterior a esta columna que no quedó en error) no se vuelve a emitir:
     * Hacienda lo rechazaría como duplicado y la numeración quedaría rota.
     * Un intento que falló antes de salir, o que Hacienda no recibió
     * (0/400/5xx), sí se puede repetir con el mismo consecutivo.
     */
    private function assertNoDuplicado(Company $company, string $consecutivo, string $tipo, string $clientId): void
    {
        $previo = Document::where('company_id', $company->id)
            ->where('env', $clientId)
            ->where('tipo_documento', $tipo)
            ->where('consecutivo', $consecutivo)
            ->where(fn ($q) => $q
                ->where('recepcion_status', 202)
                ->orWhere(fn ($q) => $q->whereNull('recepcion_status')->whereNotIn('estado', [self::ESTADO_ERROR, self::ESTADO_GENERANDO]))
                ->orWhere(fn ($q) => $q->where('estado', self::ESTADO_GENERANDO)->where('fecha_creacion', '>', now()->subMinutes(10))))
            ->orderByDesc('id')
            ->first();

        if ($previo !== null) {
            throw new DuplicateDocumentException(
                "Ya existe el comprobante $tipo con consecutivo $consecutivo (clave {$previo->clave}, document_id {$previo->id}, estado {$previo->estado}).",
                $previo->id,
            );
        }
    }

    /**
     * XML del comprobante con el generador verbatim: emisor de la empresa +
     * datos del cliente + clave y consecutivo ya resueltos.
     */
    private function buildXml(Company $company, string $tipo, array $documentParams, string $clave, string $consecutivo): string
    {
        $params = LegacyParams::fromArray(array_merge(
            $this->emisorParams($company),
            $documentParams,
            ['clave' => $clave, 'consecutivo' => $consecutivo],
            $tipo === 'MR' ? $this->mrParams($company, $documentParams, $consecutivo) : [],
        ));

        require_once app_path('Services/Xml/Legacy/genxml_functions.php');
        $fn = 'App\\Services\\Xml\\Legacy\\'.self::GENERATORS[$tipo];
        try {
            $generated = CurrentParams::with($params, fn () => $fn());
        } catch (LegacyReplyKill $e) {
            // Error de validación del generador (p. ej. un código de referencia fuera del catálogo).
            throw new EmissionException($e->getMessage(), previous: $e);
        }

        if (! is_array($generated) || ! isset($generated['xml'])) {
            throw new EmissionException('El generador no produjo XML.');
        }

        return base64_decode($generated['xml']);
    }

    /**
     * El mensaje receptor confirma un comprobante AJENO: la clave es la del
     * comprobante recibido, el receptor es la empresa y el consecutivo es
     * el de sus confirmaciones.
     *
     * @return array<string, string>
     */
    private function mrParams(Company $company, array $documentParams, string $consecutivo): array
    {
        return [
            'numero_consecutivo_receptor' => $consecutivo,
            'numero_cedula_receptor' => $this->cedulaEmisor($company),
            'fecha_emision_doc' => (string) ($documentParams['fecha_emision_doc'] ?? $documentParams['fecha_emision']),
        ];
    }

    private function sign(string $p12Path, string $pin, string $xml): string
    {
        try {
            return $this->signer->sign($p12Path, $pin, $xml);
        } catch (\Throwable $e) {
            throw new EmissionException('Error firmando el comprobante: '.$e->getMessage(), previous: $e);
        }
    }

    private function assertXsd(string $xmlFirmado, string $tipo): void
    {
        $resultado = $this->xsd->validate($xmlFirmado, $this->tipoXsd($tipo, []));
        if (! $resultado['valid']) {
            throw new EmissionException('El comprobante no cumple el XSD v4.4: '.implode(' | ', $resultado['errors']));
        }
    }

    private function tipoXsd(string $tipo, array $documentParams): string
    {
        return $tipo === 'MR' ? 'CCE' : $tipo;
    }

    /**
     * JSON de /recepcion (Anexo 3). El mensaje receptor manda además el
     * receptor y consecutivoReceptor, como sendMensaje del legacy.
     *
     * @return array<string, mixed>
     */
    private function payloadRecepcion(Company $company, string $tipo, array $documentParams, Document $document, string $consecutivo, string $xmlFirmado): array
    {
        $payload = [
            'clave' => $document->clave,
            'fecha' => (string) $documentParams['fecha_emision'],
            'emisor' => [
                'tipoIdentificacion' => (string) $company->tipo_cedula,
                'numeroIdentificacion' => $this->cedulaEmisor($company),
            ],
            'comprobanteXml' => base64_encode($xmlFirmado),
        ];

        if ($tipo === 'MR') {
            $payload['emisor'] = [
                'tipoIdentificacion' => (string) $documentParams['tipo_identificacion_emisor'],
                'numeroIdentificacion' => (string) $documentParams['numero_cedula_emisor'],
            ];
            $payload['receptor'] = [
                'tipoIdentificacion' => (string) $company->tipo_cedula,
                'numeroIdentificacion' => $this->cedulaEmisor($company),
            ];
            $payload['consecutivoReceptor'] = str_pad($consecutivo, 20, '0', STR_PAD_LEFT);
        }

        if (filled($documentParams['callback_url'] ?? null)) {
            $payload['callbackUrl'] = (string) $documentParams['callback_url'];
        }

        return $payload;
    }

    /**
     * La cédula jurídica se manda normalizada (mayúsculas, sin guiones) en
     * la clave, el XML y la recepción; las demás van tal cual.
     */
    private function cedulaEmisor(Company $company): string
    {
        $cedula = (string) $company->cedula;

        return in_array((string) $company->tipo_cedula, ['juridico', '02'], true)
            ? ClaveService::normalizarCedula($cedula)
            : $cedula;
    }

    private function credential(Company $company, string $environment): HaciendaCredential
    {
        $cred = HaciendaCredential::where('company_id', $company->id)
            ->where('environment', $environment)
            ->first();

        if ($cred === null) {
            throw new EmissionException("La empresa no tiene credenciales para el ambiente '$environment'.");
        }

        return $cred;
    }

    /** Ruta legible del .p12 (temporal si está cifrado en reposo); liberar con FileStorageService::discardTemp(). */
    private function p12(HaciendaCredential $credential): string
    {
        $file = StoredFile::where('download_code', $credential->p12_download_code)->first();
        $path = $file ? $this->files->readablePath($file) : null;

        if ($path === null) {
            throw new EmissionException('No se encontró el certificado .p12 de la empresa.');
        }

        return $path;
    }

    /**
     * Mapea los datos del emisor de la empresa a los parámetros del generador.
     *
     * @return array<string, string>
     */
    private function emisorParams(Company $company): array
    {
        return [
            // Cédula del proveedor del sistema (Anexos pág. 18); si es
            // desarrollo propio, la del emisor.
            'proveedor_sistemas' => filled($company->proveedor_sistemas)
                ? ClaveService::normalizarCedula((string) $company->proveedor_sistemas)
                : $this->cedulaEmisor($company),
            'emisor_nombre' => (string) $company->nombre,
            'emisor_tipo_identif' => (string) $company->tipo_cedula,
            'emisor_num_identif' => $this->cedulaEmisor($company),
            'emisor_nombre_comercial' => (string) $company->nombre_comercial,
            'emisor_provincia' => $this->geoCode($company->id_provincia, 1),
            'emisor_canton' => $this->geoCode($company->id_canton, 2),
            'emisor_distrito' => $this->geoCode($company->id_distrito, 2),
            'emisor_barrio' => $this->barrioName($company),
            'emisor_otras_senas' => (string) $company->sennas,
            'emisor_cod_pais_tel' => (string) $company->tel_cod_pais,
            'emisor_tel' => (string) $company->tel_numero,
            'emisor_email' => (string) $company->email,
            'cod_moneda' => 'CRC',
            'tipo_cambio' => (string) ($company->tipo_cambio ?: '1'),
        ];
    }

    /**
     * En v4.4 el Barrio dejó de ser código: es texto descriptivo con mínimo
     * 5 caracteres. Se resuelve el nombre desde el catálogo de codificación;
     * sin nombre válido, se omite (el elemento es opcional).
     */
    private function barrioName(Company $company): string
    {
        if (blank($company->id_barrio) || (int) $company->id_barrio === 0) {
            return '';
        }

        $nombre = trim((string) DB::table('codificacion_mh')
            ->where('id_provincia', $company->id_provincia)
            ->where('id_canton', $company->id_canton)
            ->where('id_distrito', $company->id_distrito)
            ->where('id_barrio', $company->id_barrio)
            ->value('nombre_barrio'));

        return mb_strlen($nombre) >= 5 ? $nombre : '';
    }

    /**
     * Normaliza un código de ubicación al ancho exacto del esquema v4.4
     * (Provincia \d, Canton/Distrito \d\d). El catálogo legacy mezcla
     * anchos ("015", "4"); "00" o vacío significa "sin dato" y se devuelve
     * vacío para que el generador omita el elemento.
     */
    private function geoCode(?string $value, int $length): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '' || (int) $digits === 0) {
            return '';
        }

        return str_pad(ltrim($digits, '0'), $length, '0', STR_PAD_LEFT);
    }
}
