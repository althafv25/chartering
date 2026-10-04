<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name') ?: 'web',
            $request->ip(),
        );

        return $this->ok([
            'token' => $result['token'],
            'expires_at' => $result['expires_at'],
            'user' => (new AuthUserResource($result['user']->load('roles')))->resolve($request),
        ], 'Signed in.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new AuthUserResource($request->user()->load('roles')));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return $this->ok(null, 'Signed out.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->sendResetLink($request->validated('email'));

        return $this->ok(null, 'If the address belongs to an active account, a reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->validated('email'), $request->validated('token'), $request->validated('password'));

        return $this->ok(null, 'Your password has been reset. Please sign in.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword($request->user(), $request->validated('current_password'), $request->validated('password'));

        return $this->ok(null, 'Password changed. Other sessions have been signed out.');
    }
}
