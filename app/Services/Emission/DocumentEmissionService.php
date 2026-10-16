<?php

namespace App\Services\Emission;

use App\Legacy\LegacyParams;
use App\Models\Company;
use App\Models\Consecutive;
use App\Models\Document;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Services\Clave\ClaveService;
use App\Services\Hacienda\ReceptionClient;
use App\Services\Hacienda\TokenService;
use App\Services\Signature\XadesSignerService;
use App\Services\Xml\Legacy\CurrentParams;
use App\Services\Xml\Legacy\LegacyReplyKill;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta la emisión completa de un comprobante electrónico para una
 * empresa: clave → XML (generador v4.4 verbatim) → firma XAdES → token
 * Hacienda → envío → persistencia. Es la implementación real del flujo
 * "todo en uno" que el módulo legacy crlibreall dejó como stub.
 *
 * Reutiliza los mismos Services que la capa de compatibilidad, de modo que
 * el XML emitido es byte-idéntico al del API legacy para los mismos datos.
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
        'MR' => 'genXMLMr',
    ];

    public function __construct(
        private readonly ClaveService $clave,
        private readonly XadesSignerService $signer,
        private readonly TokenService $tokens,
        private readonly ReceptionClient $reception,
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
     * @return array{clave: string, consecutivo: string, xml: string, xmlFirmado: string, envio: array, document_id: int}
     *
     * @throws EmissionException
     */
    public function emit(Company $company, string $tipo, array $documentParams, string $environment): array
    {
        if (! isset(self::GENERATORS[$tipo])) {
            throw new EmissionException("Tipo de documento no soportado: $tipo");
        }

        $clientId = $environment === 'prod' ? 'api-prod' : 'api-stag';
        $credential = $this->credential($company, $environment);

        // 1. Consecutivo y clave.
        $consecutivo = $documentParams['consecutivo']
            ?? $this->nextConsecutivo($company, $tipo, $clientId);

        $claveResult = $this->clave->generate(
            tipoDocumento: $tipo === 'MR' ? 'CCE' : $tipo,
            tipoCedula: (string) $company->tipo_cedula,
            cedula: (string) $company->cedula,
            situacion: $company->situacion ?: 'normal',
            codigoPais: '506',
            consecutivo: substr((string) $consecutivo, -10),
            codigoSeguridad: str_pad((string) ($documentParams['codigoSeguridad'] ?? '0'), 8, '0', STR_PAD_LEFT),
            sucursal: (string) ($documentParams['sucursal'] ?? '001'),
            terminal: (string) ($documentParams['terminal'] ?? '00001'),
        );

        if (is_string($claveResult)) {
            throw new EmissionException('Error generando la clave: '.$claveResult);
        }

        // 2. XML (generador verbatim) con emisor de la empresa + datos del cliente.
        $params = LegacyParams::fromArray(array_merge(
            $this->emisorParams($company),
            $documentParams,
            [
                'clave' => $claveResult['clave'],
                'consecutivo' => $claveResult['consecutivo'],
            ],
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

        $xml = base64_decode($generated['xml']);

        // 3. Firma XAdES con el .p12 de la empresa.
        $p12Path = $this->p12Path($company, $credential);
        try {
            $xmlFirmado = $this->signer->sign($p12Path, (string) $credential->pin, $xml);
        } catch (\Throwable $e) {
            throw new EmissionException('Error firmando el comprobante: '.$e->getMessage(), previous: $e);
        }

        // 4. Token Hacienda.
        $tokenResponse = $this->tokens->requestToken([
            'client_id' => $clientId,
            'grant_type' => 'password',
            'username' => (string) $credential->username,
            'password' => (string) $credential->password,
        ]);
        $accessToken = is_object($tokenResponse) ? ($tokenResponse->access_token ?? null) : null;
        if (! $accessToken) {
            throw new EmissionException('No se pudo obtener el token de Hacienda.');
        }

        // 5. Envío a recepción.
        $envio = $this->reception->send([
            'clave' => $claveResult['clave'],
            'fecha' => $documentParams['fecha_emision'] ?? now()->format('Y-m-d\TH:i:sP'),
            'emisor' => [
                'tipoIdentificacion' => $company->tipo_cedula,
                'numeroIdentificacion' => $company->cedula,
            ],
            'comprobanteXml' => base64_encode($xmlFirmado),
        ], $clientId, $accessToken);

        // 6. Persistencia.
        $document = Document::create([
            'company_id' => $company->id,
            'consecutivo' => $claveResult['consecutivo'],
            'clave' => $claveResult['clave'],
            'tipo_documento' => $tipo,
            'estado' => 'enviado',
            'xml_enviado_base64' => base64_encode($xmlFirmado),
            'respuesta_mh_base64' => null,
            'env' => $clientId,
        ]);

        return [
            'clave' => $claveResult['clave'],
            'consecutivo' => $claveResult['consecutivo'],
            'xml' => $generated['xml'],
            'xmlFirmado' => base64_encode($xmlFirmado),
            'envio' => $envio,
            'document_id' => $document->id,
        ];
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

    private function p12Path(Company $company, HaciendaCredential $credential): string
    {
        $file = StoredFile::where('download_code', $credential->p12_download_code)->first();
        $path = $file ? storage_path('app/'.$file->path) : null;

        if ($path === null || ! is_file($path)) {
            throw new EmissionException('No se encontró el certificado .p12 de la empresa.');
        }

        return $path;
    }

    /** Incrementa y devuelve el consecutivo de 20 dígitos para el tipo/ambiente. */
    private function nextConsecutivo(Company $company, string $tipo, string $clientId): string
    {
        return DB::transaction(function () use ($company, $tipo, $clientId) {
            $row = Consecutive::where('company_id', $company->id)
                ->where('env', $clientId)
                ->where('tipo_comprobante', $tipo)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = Consecutive::create([
                    'company_id' => $company->id,
                    'env' => $clientId,
                    'tipo_comprobante' => $tipo,
                    'numero_consecutivo' => 0,
                    'company_name' => $company->nombre,
                ]);
            }

            $row->increment('numero_consecutivo');

            return str_pad((string) $row->numero_consecutivo, 10, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Mapea los datos del emisor de la empresa a los parámetros del generador.
     *
     * @return array<string, string>
     */
    private function emisorParams(Company $company): array
    {
        return [
            'proveedor_sistemas' => (string) $company->cedula,
            'emisor_nombre' => (string) $company->nombre,
            'emisor_tipo_identif' => (string) $company->tipo_cedula,
            'emisor_num_identif' => (string) $company->cedula,
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
