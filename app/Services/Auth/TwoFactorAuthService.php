<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use App\Models\User;

class TwoFactorAuthService
{
    private const int BACKUP_CODES_COUNT = 8;
    private const int BACKUP_CODES_LENGTH = 8;
    private const string VERIFICATION_GRACE_PERIOD_KEY = '2fa_grace_period:';
    private const int TOTP_PERIOD = 30;
    private const int TOTP_DIGITS = 6;

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

    public function setGracePeriod(string $userId, int $minutes): void
    {
        $key = self::VERIFICATION_GRACE_PERIOD_KEY . $userId;
        Cache::put($key, true, now()->addMinutes($minutes));
    }

    public function hasGracePeriod(string $userId): bool
    {
        $key = self::VERIFICATION_GRACE_PERIOD_KEY . $userId;

        return Cache::has($key);
    }

    public function clearGracePeriod(string $userId): void
    {
        $key = self::VERIFICATION_GRACE_PERIOD_KEY . $userId;
        Cache::forget($key);
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
