<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TwoFactorAuthService
{
    private const int BACKUP_CODES_COUNT = 8;
    private const int BACKUP_CODES_LENGTH = 8;
    private const string VERIFICATION_GRACE_PERIOD_KEY = 'auth.2fa_verified_until.';
    private const int TOTP_PERIOD = 30;
    private const int TOTP_DIGITS = 6;
    private const int TOTP_MAX_ATTEMPTS = 5;
    private const int TOTP_LOCKOUT_SECONDS = 900;

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return $this->base32Encode($bytes);
    }

    public function verifyTotp(User $user, string $code): bool
    {
        if ($this->verify($user->two_factor_secret, $code)) {
            return true;
        }

        $backupCodes = $user->two_factor_backup_codes;

        if ($this->verifyBackupCode($backupCodes, $code)) {
            $updatedCodes = $this->removeUsedBackupCode($backupCodes, $code);
            $user->forceFill([
                'two_factor_backup_codes' => $updatedCodes,
            ])->save();

            return true;
        }

        return false;
    }

    /**
     * verifyTotp() with a failure counter per account, for the sign-in paths.
     * The per-minute request throttles leave room to walk the six digits, and
     * three codes are valid at any moment. The counter is the one the step-up
     * header check uses (ThrottlesStepUpVerification), so both share a budget.
     * A locked account fails every attempt, see totpLockoutSeconds().
     */
    public function attemptTotp(User $user, string $code): bool
    {
        $key = $this->totpAttemptsKey($user);

        if (RateLimiter::tooManyAttempts($key, self::TOTP_MAX_ATTEMPTS)) {
            return false;
        }

        if ($this->verifyTotp($user, $code)) {
            RateLimiter::clear($key);

            return true;
        }

        RateLimiter::hit($key, self::TOTP_LOCKOUT_SECONDS);

        return false;
    }

    /** Seconds until the account may try a code again, or 0 when it is not locked. */
    public function totpLockoutSeconds(User $user): int
    {
        $key = $this->totpAttemptsKey($user);

        return RateLimiter::tooManyAttempts($key, self::TOTP_MAX_ATTEMPTS) ? RateLimiter::availableIn($key) : 0;
    }

    private function totpAttemptsKey(User $user): string
    {
        return "step-up:totp:{$user->getKey()}";
    }

    public function verify(string $secret, string $code): bool
    {
        $currentTimestamp = time();

        for ($i = -1; $i <= 1; $i++) {
            $timestamp = $currentTimestamp + ($i * self::TOTP_PERIOD);
            $expectedCode = $this->generateTotp($secret, $timestamp);

            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    public function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODES_COUNT; $i++) {
            $codes[] = $this->generateBackupCode();
        }

        return $codes;
    }

    public function verifyBackupCode(array $backupCodes, string $code): bool
    {
        return \in_array($code, $backupCodes, true);
    }

    public function removeUsedBackupCode(array $backupCodes, string $usedCode): array
    {
        return array_values(array_filter($backupCodes, fn($code) => $code !== $usedCode));
    }

    /**
     * A verified second factor counts for this browser session only. Keyed per
     * user so a later login in the same session never inherits it. Requests
     * without a session (bearer tokens) get no grace and must send the factor.
     */
    public function setGracePeriod(Request $request, User $user, int $minutes): void
    {
        if ($request->hasSession()) {
            $request->session()->put(
                self::VERIFICATION_GRACE_PERIOD_KEY.$user->id,
                now()->addMinutes($minutes)->getTimestamp(),
            );
        }
    }

    public function hasGracePeriod(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $until = $request->session()->get(self::VERIFICATION_GRACE_PERIOD_KEY.$user->id);

        return \is_int($until) && $until > now()->getTimestamp();
    }

    private function generateTotp(string $secret, int $timestamp): string
    {
        $secret = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', intdiv($timestamp, self::TOTP_PERIOD));

        $hash = hash_hmac('sha1', $time, $secret, true);
        $offset = \ord($hash[19]) & 0xf;
        $binary = (\ord($hash[$offset]) & 0x7f) << 24 |
            (\ord($hash[$offset + 1]) & 0xff) << 16 |
            (\ord($hash[$offset + 2]) & 0xff) << 8 |
            (\ord($hash[$offset + 3]) & 0xff);

        $otp = $binary % (10 ** self::TOTP_DIGITS);

        return str_pad((string) $otp, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = '';
        $length = \strlen($data);

        for ($i = 0; $i < $length; $i += 5) {
            $chunk = substr($data, $i, 5);
            $binary = '';

            for ($j = 0; $j < \strlen($chunk); $j++) {
                $binary .= str_pad(decbin(\ord($chunk[$j])), 8, '0', STR_PAD_LEFT);
            }

            $binary = str_pad($binary, 40, '0', STR_PAD_RIGHT);

            for ($j = 0; $j < 40; $j += 5) {
                $index = bindec(substr($binary, $j, 5));
                $encoded .= $map[$index];
            }
        }

        return $encoded;
    }

    private function base32Decode(string $data): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $decoded = '';
        $length = \strlen($data);
        $binary = '';

        for ($i = 0; $i < $length; $i++) {
            $index = strpos($map, strtoupper($data[$i]));
            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        for ($i = 0; $i < \strlen($binary); $i += 8) {
            $byte = substr($binary, $i, 8);
            if (\strlen($byte) === 8) {
                $decoded .= \chr(bindec($byte));
            }
        }

        return $decoded;
    }

    private function generateBackupCode(): string
    {
        $characters = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        $max = \strlen($characters) - 1;

        for ($i = 0; $i < self::BACKUP_CODES_LENGTH; $i++) {
            $code .= $characters[random_int(0, $max)];
        }

        return $code;
    }
}
