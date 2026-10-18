<?php

namespace App\Core\Auth\Services;

use App\Core\User\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Random\RandomException;

class AuthService
{
    public function login(string $email, string $password): array
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'account' => ['Account is inactive.'],
            ]);
        }

        $user->load('roles', 'permissions');

        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }

    public function register(array $data): array
    {
        // Create user
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => true, // default active
        ]);

        // Assign default role (make sure this role exists in your seeder)
        $user->assignRole('user');

        // Load relationships for response
        $user->load('roles', 'permissions');

        // Generate token
        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * @throws Exception
     */
    public function verifyOtp(string $email, string $inputOtp): bool
    {
        $key = "otp:$email";
        $rateKey = "otp-attempts:$email:".request()->ip();

        $maxAttempts = 3;
        $lockoutSeconds = 600;

        $data = Cache::get($key);

        if (! $data) {
            throw new Exception('OTP expired or not found.');
        }

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($rateKey);
            throw new Exception("Too many attempts. Try again in {$seconds} seconds.");
        }

        if (Hash::check($inputOtp, $data['otp_hash'])) {
            Cache::forget($key);
            RateLimiter::clear($rateKey);

            return true;
        }

        RateLimiter::hit($rateKey, $lockoutSeconds);

        throw new Exception('Invalid OTP.');
    }

    /**
     * @throws RandomException
     */
    public function generateOtp(string $email): string
    {
        $otp = (string) random_int(100000, 999999);

        $key = "otp:$email";

        Cache::put($key, [
            'otp_hash' => Hash::make($otp),
        ], now()->addMinutes(10));

        return $otp;
    }

    public function generate2FaOtp(int $userId): string
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put("2fa_otp:{$userId}", [
            'otp_hash' => Hash::make($otp),
        ], now()->addMinutes(10));

        return $otp;
    }

    public function verify2FaOtp(int $userId, string $inputOtp): bool
    {
        $key = "2fa_otp:{$userId}";
        $rateKey = "2fa_otp_attempts:{$userId}:".request()->ip();

        $data = Cache::get($key);
        if (! $data) {
            throw new Exception('OTP expired or not found. Please request a new one.');
        }

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            throw new Exception("Too many attempts. Try again in {$seconds} seconds.");
        }

        if (Hash::check($inputOtp, $data['otp_hash'])) {
            Cache::forget($key);
            RateLimiter::clear($rateKey);

            return true;
        }

        RateLimiter::hit($rateKey, 600);
        throw new Exception('Invalid OTP. Please check and try again.');
    }

    public function generateBulkOtp(int $userId): string
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put("bulk_adjust_otp:{$userId}", [
            'otp_hash' => Hash::make($otp),
        ], now()->addMinutes(15));

        return $otp;
    }

    public function verifyBulkOtp(int $userId, string $inputOtp): bool
    {
        $key = "bulk_adjust_otp:{$userId}";
        $rateKey = "bulk_otp_attempts:{$userId}:".request()->ip();

        $data = Cache::get($key);
        if (! $data) {
            throw new Exception('OTP expired or not found. Please request a new one.');
        }

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            throw new Exception("Too many attempts. Try again in {$seconds} seconds.");
        }

        if (Hash::check($inputOtp, $data['otp_hash'])) {
            Cache::forget($key);
            RateLimiter::clear($rateKey);

            return true;
        }

        RateLimiter::hit($rateKey, 600);
        throw new Exception('Invalid OTP. Please check and try again.');
    }
}
