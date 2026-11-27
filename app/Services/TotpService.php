<?php

namespace App\Services;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        $key = $this->base32Decode($secret);
        $counter = intdiv($timestamp, 30);
        $message = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestamp ??= time();

        for ($window = -1; $window <= 1; $window++) {
            if (hash_equals($this->codeAt($secret, $timestamp + ($window * 30)), $code)) {
                return true;
            }
        }

        return false;
    }

    private function base32Encode(string $value): string
    {
        $bits = '';
        foreach (unpack('C*', $value) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value));
        $bits = '';

        foreach (str_split($value) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid Base32 TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        if ($decoded === '') {
            throw new \InvalidArgumentException('Invalid Base32 TOTP secret.');
        }

        return $decoded;
    }
}
