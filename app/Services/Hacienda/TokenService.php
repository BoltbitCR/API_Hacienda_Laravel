<?php

namespace App\Services\Hacienda;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth contra el IDP (Keycloak) de Hacienda. Port de
 * legacy/api/contrib/token/mhToken.php::token().
 *
 * Contrato conservado:
 *  - Los mensajes de validación son strings con status ok (no killMe).
 *  - La respuesta del IDP se devuelve decodificada tal cual (el legacy
 *    hacía json_decode del body; su CURLOPT_HEADER quedaba en false porque
 *    el string 'Content-Type...' castea a 0).
 *  - IDP inalcanzable o client_id desconocido => resp null.
 */
class TokenService
{
    public function __construct(private readonly HaciendaEnvironment $env) {}

    /**
     * @param  array{client_id: string, grant_type: string, client_secret?: string,
     *     username?: string, password?: string, refresh_token?: string}  $in
     */
    public function requestToken(array $in): mixed
    {
        $clientId = $in['client_id'] ?? '';
        $grantType = $in['grant_type'] ?? '';

        $url = $this->env->idpUrl($clientId);

        $data = [];
        if ($grantType === 'password') {
            if ($clientId === '') {
                return 'El parametro Client ID es requerido';
            } elseif (($in['username'] ?? '') === '') {
                return 'El parametro Username es requerido';
            } elseif (($in['password'] ?? '') === '') {
                return 'El parametro Password es requerido';
            }

            $data = [
                'client_id' => $clientId,
                'client_secret' => $in['client_secret'] ?? '',
                'grant_type' => $grantType,
                'username' => $in['username'],
                'password' => $in['password'],
            ];
        } elseif ($grantType === 'refresh_token') {
            if ($clientId === '') {
                return 'El parametro Client ID es requerido';
            } elseif (($in['refresh_token'] ?? '') === '') {
                return 'El parametro Refresh Token es requerido';
            }

            $data = [
                'client_id' => $clientId,
                'client_secret' => $in['client_secret'] ?? '',
                'grant_type' => $grantType,
                'refresh_token' => $in['refresh_token'],
            ];
        }

        try {
            $response = Http::asForm()
                ->withOptions(['verify' => $this->env->verifySsl()])
                ->timeout($this->env->timeout())
                ->post((string) $url, $data);
        } catch (\Throwable $e) {
            // El legacy con url null / error de red devolvía resp null
            // (json_decode de false). Errores curl reales: {Status, text}.
            return null;
        }

        return json_decode($response->body());
    }

    /**
     * Access token vigente para las credenciales ATV, reutilizado entre
     * peticiones: el IdP lo emite por 5 minutos (Anexo 3) y pedir uno nuevo
     * en cada emisión y consulta es carga inútil contra Hacienda. La caché
     * guarda solo el token, bajo una llave derivada de las credenciales.
     */
    public function accessToken(string $clientId, string $username, string $password): ?string
    {
        $key = 'hacienda:token:'.$clientId.':'.hash('sha256', $username.'|'.$password);

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->requestToken([
            'client_id' => $clientId,
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
        ]);

        $token = is_object($response) ? ($response->access_token ?? null) : null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        // Margen de 30 s sobre expires_in (300 s en Hacienda) y tope de 270 s.
        $ttl = max(30, min((int) ($response->expires_in ?? 300) - 30, 270));
        Cache::put($key, $token, $ttl);

        return $token;
    }
}
