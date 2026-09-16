<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\ApiErrorResponder;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected AuthService $service;

    public function __construct(AuthService $service)
    {
        $this->service = $service;
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            $result = $this->service->login($credentials['email'], $credentials['password']);

            return $this->credentialResponse($result, $request);
        } catch (\Throwable $e) {
            $response = app(ApiErrorResponder::class)->toResponse($e, $request, 401);
            $payload = $response->getData(true);
            $payload['message'] = $payload['error'];

            return response()->json($payload, $response->getStatusCode(), $response->headers->all());
        }
    }

    public function token(Request $request)
    {
        return $this->login($request);
    }

    public function refresh(Request $request)
    {
        try {
            return $this->credentialResponse(
                $this->service->refresh($request->cookie('refresh_token')),
                $request,
            );
        } catch (\Throwable $e) {
            $response = app(ApiErrorResponder::class)->toResponse($e, $request, 401);
            $payload = $response->getData(true);
            $payload['message'] = $payload['error'];

            return response()
                ->json($payload, 401, $response->headers->all())
                ->withCookie($this->forgetRefreshCookie());
        }
    }

    public function logout(Request $request)
    {
        $this->service->revokeRefreshToken($request->cookie('refresh_token'));
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Đăng xuất thành công'])->withCookie($this->forgetRefreshCookie());
    }

    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /** @param array<string,mixed> $result */
    private function credentialResponse(array $result, Request $request)
    {
        $refreshToken = $result['refresh_token'] ?? null;
        unset($result['refresh_token']);

        return response()->json($result)->withCookie($this->refreshCookie($request, (string) $refreshToken));
    }

    private function refreshCookie(Request $request, string $value)
    {
        $secure = $request->isSecure() || (bool) config('session.secure', false);

        return cookie(
            'refresh_token',
            $value,
            $this->service->refreshTokenLifetimeMinutes(),
            '/api/v1/auth',
            config('session.domain'),
            $secure,
            true,
            false,
            'lax',
        );
    }

    private function forgetRefreshCookie()
    {
        return cookie()->forget('refresh_token', '/api/v1/auth', config('session.domain'));
    }
}
