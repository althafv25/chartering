<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @return array{user: User, token: string, expires_at: ?string}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, string $deviceName, ?string $ip): array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', mb_strtolower($email))->first();

        // Same message for unknown email and wrong password (no user enumeration).
        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['email' => ['Your account is inactive. Please contact an administrator.']]);
        }

        $expiration = (int) config('sanctum.expiration');
        $expiresAt = $expiration > 0 ? now()->addMinutes($expiration) : null;

        $token = DB::transaction(function () use ($user, $deviceName, $expiresAt) {
            // One active token per device name prevents token accumulation.
            $user->tokens()->where('name', $deviceName)->delete();

            return $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;
        });

        $user->recordLogin($ip);

        activity('auth')->performedOn($user)->causedBy($user)
            ->event('login')->withProperties(['device' => $deviceName])->log('User logged in');

        return ['user' => $user, 'token' => $token, 'expires_at' => $expiresAt?->toIso8601String()];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();

        activity('auth')->performedOn($user)->causedBy($user)->event('logout')->log('User logged out');
    }

    /** Always succeeds from the caller's perspective (no user enumeration). */
    public function sendResetLink(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if ($user && $user->isActive()) {
            Password::sendResetLink(['email' => $user->email]);
        }
    }

    /** @throws ValidationException */
    public function resetPassword(string $email, string $token, string $password): void
    {
        $status = Password::reset(
            ['email' => mb_strtolower($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                ])->save();

                $user->tokens()->delete();
                event(new PasswordReset($user));

                activity('auth')->performedOn($user)->causedBy($user)->event('password_reset')->log('Password reset');
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }
    }

    /** @throws ValidationException */
    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $user->forceFill(['password' => $new, 'password_changed_at' => now()])->save();

        // Sign out other devices, keep the current token.
        $currentId = $user->currentAccessToken()?->getKey();
        $user->tokens()->when($currentId, fn ($q) => $q->whereKeyNot($currentId))->delete();

        activity('auth')->performedOn($user)->causedBy($user)->event('password_changed')->log('Password changed');
    }
}
