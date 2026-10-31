<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompanyLoginRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Support\ActingCompany;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Auth\CompanyUserAuthService;
use App\Services\Auth\LegacyAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación de la API REST v1 mediante tokens Sanctum.
 *
 * Emite dos tipos de token, distinguidos por su ability:
 *  - "master": usuario de plataforma (dueño de una empresa).
 *  - "company": sub-usuario de una empresa.
 *
 * Reutiliza la verificación de contraseñas legacy (bcrypt + fallback md5 con
 * rehash transparente), de modo que las credenciales migradas funcionan sin
 * que el usuario tenga que restablecerlas.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly LegacyAuthService $masterAuth,
        private readonly CompanyUserAuthService $companyAuth,
    ) {}

    /**
     * Registro de usuario master: crea el usuario, su empresa y devuelve un
     * token Sanctum (auto-login, como hacía el registro legacy).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        abort_unless(config('auth.registration_enabled'), 403, 'El registro de cuentas está deshabilitado en esta instalación.');

        $user = User::create([
            'full_name' => $request->string('full_name')->value(),
            'user_name' => $request->string('username')->value(),
            'email' => $request->string('email')->value(),
            'about' => '',
            'country' => 'crc',
            'status' => '1',
            'legacy_timestamp' => time(),
            'last_access' => time(),
            'password' => $this->masterAuth->hash($request->string('password')->value()),
            'avatar' => '0',
            'settings' => null,
        ]);

        $company = Company::create([
            'owner_user_id' => $user->id,
            'nombre' => $request->string('company_name')->value() ?: $request->string('full_name')->value(),
            'email' => $user->email,
        ]);

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'api',
            ['master']
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'principal' => 'master',
            'user' => [
                'id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'company_id' => $company->id,
            ],
        ], 201);
    }

    /**
     * Login de usuario master (dueño de empresa).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->masterAuth->loadByUserNameOrEmail($request->string('username')->value());

        if ($user === null || ! $this->masterAuth->verifyPassword($user, $request->string('password')->value())) {
            throw ValidationException::withMessages(['username' => 'Credenciales inválidas.']);
        }

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'api',
            ['master']
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'principal' => 'master',
            'user' => [
                'id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'company_id' => ActingCompany::idFor($user),
            ],
        ]);
    }

    /**
     * Login de sub-usuario de una empresa.
     */
    public function companyLogin(CompanyLoginRequest $request): JsonResponse
    {
        $companyId = $request->integer('company_id');
        $user = $this->companyAuth->loadByUserNameOrEmail($companyId, $request->string('username')->value());

        if ($user === null || ! $this->companyAuth->verifyPassword($user, $request->string('password')->value())) {
            throw ValidationException::withMessages(['username' => 'Credenciales inválidas.']);
        }

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'api',
            ['company']
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'principal' => 'company',
            'user' => [
                'id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'company_id' => $companyId,
            ],
        ]);
    }

    /**
     * Datos del principal autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $principal = $request->user();

        return response()->json([
            'principal' => $principal instanceof CompanyUser ? 'company' : 'master',
            'id' => $principal->id,
            'user_name' => $principal->user_name,
            'email' => $principal->email,
            'company_id' => ActingCompany::idFor($principal),
            'abilities' => $request->user()->currentAccessToken()->abilities ?? [],
        ]);
    }

    /**
     * Revoca el token actual (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }
}
