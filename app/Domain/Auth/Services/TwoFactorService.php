<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TOTP-based Two-Factor Authentication service.
 * Uses HMAC-based One-Time Passwords (RFC 6238).
 */
class TwoFactorService
{
    private const PERIOD = 30; // Seconds per code
    private const DIGITS = 6;
    private const ALGORITHM = 'sha1';

    /**
     * Generate a new 2FA secret for a user.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Generate recovery codes.
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        return Collection::times($count, fn () => Str::random(10))->toArray();
    }

    /**
     * Enable 2FA for a user.
     */
    public static function enable(User $user): array
    {
        $secret = self::generateSecret();
        $recoveryCodes = self::generateRecoveryCodes();

        $user->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_enabled' => true,
        ]);

        return [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
            'qr_uri' => self::getQrUri($user->email, $secret),
        ];
    }

    /**
     * Disable 2FA for a user.
     */
    public static function disable(User $user): void
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_enabled' => false,
        ]);
    }

    /**
     * Verify a TOTP code.
     */
    public static function verify(User $user, string $code): bool
    {
        if (! $user->two_factor_enabled || ! $user->two_factor_secret) {
            return true; // 2FA not enabled, always pass
        }

        $secret = decrypt($user->two_factor_secret);

        // Check current and adjacent time windows (±1 period for clock drift)
        $time = (int) floor(time() / self::PERIOD);

        for ($i = -1; $i <= 1; $i++) {
            if (self::generateCode($secret, $time + $i) === $code) {
                return true;
            }
        }

        // Check recovery codes
        return self::verifyRecoveryCode($user, $code);
    }

    /**
     * Verify and consume a recovery code.
     */
    private static function verifyRecoveryCode(User $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        if (! is_array($codes)) {
            return false;
        }

        $key = array_search($code, $codes, true);

        if ($key === false) {
            return false;
        }

        // Remove used code
        unset($codes[$key]);
        $user->update([
            'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
        ]);

        return true;
    }

    /**
     * Generate a TOTP code for a given time counter.
     */
    private static function generateCode(string $secret, int $counter): string
    {
        $binary = self::base32Decode($secret);
        $time = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGORITHM, $time, $binary, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Generate otpauth:// URI for QR code generation.
     */
    public static function getQrUri(string $email, string $secret): string
    {
        $issuer = rawurlencode(config('app.name', 'Muhasebi'));
        $email = rawurlencode($email);

        return "otpauth://totp/{$issuer}:{$email}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=" . self::DIGITS . '&period=' . self::PERIOD;
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= $alphabet[bindec($chunk)];
        }
        return $result;
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split(strtoupper($data)) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos !== false) {
                $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $result .= chr(bindec($byte));
            }
        }
        return $result;
    }
}
