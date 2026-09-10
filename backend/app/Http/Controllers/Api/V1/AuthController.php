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

            return response()->json($result);
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

    public function logout(Request $request)
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Đăng xuất thành công']);
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
}
