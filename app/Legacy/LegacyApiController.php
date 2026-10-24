<?php

namespace App\Legacy;

use App\Services\Auth\LegacyAuthService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recibe todas las llamadas al endpoint de compatibilidad /api.php y las
 * despacha con la semántica del front controller legacy (www/api.php).
 */
class LegacyApiController
{
    public function __construct(private readonly LegacyDispatcher $dispatcher) {}

    public function __invoke(Request $request): Response
    {
        // Se decide en cada request (no al registrar la ruta) para que el
        // interruptor funcione aunque las rutas estén cacheadas.
        abort_unless(config('hacienda.legacy.enabled'), 404);

        try {
            $params = LegacyParams::fromRequest($request);
            $response = $this->dispatcher->dispatch($params);
            $this->touchLastAccess($params, $request);
        } catch (LegacyDieException $e) {
            $response = LegacyResponse::plainDie($e->getMessage());
        }

        // CORS abierto, como el legacy (solo en este endpoint de compat).
        $response->headers->add([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Cache-Control, Pragma, Origin, Authorization, Content-Type, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, PUT, POST',
        ]);

        return $response;
    }

    /**
     * Efecto colateral de _tools_reply(): cada request con sesión refresca
     * lastAccess de la sesión y del usuario.
     */
    private function touchLastAccess(LegacyParams $params, Request $request): void
    {
        $sessionKey = (string) $params->get('sessionKey', '');
        $iam = (string) $params->get('iam', '');
        if ($sessionKey === '' || $iam === '') {
            return;
        }

        try {
            $auth = app(LegacyAuthService::class);
            $auth->touchLastAccess($sessionKey, $auth->loadByUserNameOrEmail($iam));
        } catch (\Throwable) {
            // Sin BD disponible no hay sesión que refrescar.
        }
    }
}
