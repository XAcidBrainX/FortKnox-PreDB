<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use phpseclib3\Crypt\Blowfish;
use RuntimeException;

/** mIRC FiSH 10 compatible Blowfish ECB and Mircryption-compatible CBC. */
final class FiSH
{
    private const ALPHABET = './0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function encrypt(string $plaintext, string $configuredKey): string
    {
        [$key, $cbc] = self::normalizeKey($configuredKey);
        if ($cbc) {
            $cipher = new Blowfish('cbc');
            $cipher->setKey(substr($key, 0, 56));
            $cipher->setIV(str_repeat("\0", 8));
            $cipher->disablePadding();
            $input = random_bytes(8) . $plaintext;
            $remainder = strlen($input) % 8;
            if ($remainder !== 0) {
                $input .= str_repeat("\0", 8 - $remainder);
            }
            return '+OK *' . base64_encode($cipher->encrypt($input));
        }

        $cipher = new Blowfish('ecb');
        $cipher->setKey($key);
        $cipher->disablePadding();
        $input = $plaintext;
        $remainder = strlen($input) % 8;
        if ($remainder !== 0) {
            $input .= str_repeat("\0", 8 - $remainder);
        }
        $raw = $cipher->encrypt($input);
        $encoded = '';
        for ($offset = 0, $length = strlen($raw); $offset < $length; $offset += 8) {
            $block = unpack('Nleft/Nright', substr($raw, $offset, 8));
            $encoded .= self::encodeWord($block['right']) . self::encodeWord($block['left']);
        }
        return '+OK ' . $encoded;
    }

    public static function decrypt(string $message, string $configuredKey): ?string
    {
        if (!str_starts_with($message, '+OK ')) {
            return null;
        }
        [$key, $configuredCbc] = self::normalizeKey($configuredKey);
        $payload = substr($message, 4);
        $cbc = str_starts_with($payload, '*');
        if ($cbc) {
            $payload = substr($payload, 1);
            $raw = base64_decode($payload, true);
            if ($raw === false || $raw === '' || strlen($raw) < 16) {
                return null;
            }
            $cut = strlen($raw) % 8;
            if ($cut !== 0) {
                $raw = substr($raw, 0, -$cut);
            }
            $cipher = new Blowfish('cbc');
            $cipher->setKey(substr($key, 0, 56));
            $cipher->setIV(str_repeat("\0", 8));
            $cipher->disablePadding();
            $plain = $cipher->decrypt($raw);
            $plain = substr($plain, 8);
            return rtrim(str_replace(["\0", "\r", "\n"], '', $plain), "\0");
        }

        $length = strlen($payload);
        if ($length < 12) {
            return null;
        }
        $cut = $length % 12;
        if ($cut !== 0) {
            $payload = substr($payload, 0, -$cut);
        }
        $raw = '';
        for ($offset = 0, $length = strlen($payload); $offset < $length; $offset += 12) {
            $right = self::decodeWord(substr($payload, $offset, 6));
            $left = self::decodeWord(substr($payload, $offset + 6, 6));
            if ($right === null || $left === null) {
                return null;
            }
            $raw .= pack('N2', $left, $right);
        }
        $cipher = new Blowfish('ecb');
        $cipher->setKey($key);
        $cipher->disablePadding();
        $plain = $cipher->decrypt($raw);
        return str_replace(["\0", "\r", "\n"], '', $plain);
    }

    private static function normalizeKey(string $configuredKey): array
    {
        $cbc = str_starts_with(strtolower($configuredKey), 'cbc:');
        $key = $cbc ? substr($configuredKey, 4) : $configuredKey;
        if ($key === '') {
            throw new RuntimeException('FiSH key must not be empty.');
        }
        if (!$cbc && strlen($key) > 56) {
            throw new RuntimeException('FiSH ECB keys may not exceed 56 bytes.');
        }
        return [substr($key, 0, 56), $cbc];
    }

    private static function encodeWord(int $word): string
    {
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= self::ALPHABET[$word & 0x3f];
            $word = ($word >> 6) & 0x03ffffff;
        }
        return $out;
    }

    private static function decodeWord(string $encoded): ?int
    {
        if (strlen($encoded) !== 6) {
            return null;
        }
        $value = 0;
        for ($i = 5; $i >= 0; $i--) {
            $digit = strpos(self::ALPHABET, $encoded[$i]);
            if ($digit === false) {
                return null;
            }
            $value = (($value << 6) | $digit) & 0xffffffff;
        }
        return $value;
    }
}
