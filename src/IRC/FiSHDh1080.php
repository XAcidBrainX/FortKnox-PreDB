<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use phpseclib3\Math\BigInteger;
use RuntimeException;

/** FiSH 10 DH1080 key exchange with its fixed prime and key derivation. */
final class FiSHDh1080
{
    private const PRIME_B64 = '++ECLiPSE+is+proud+to+present+latest+FiSH+release+featuring+even+more+security+for+you+++shouts+go+out+to+TMG+for+helping+to+generate+this+cool+sophie+germain+prime+number++++/C32L';
    private const WIDTH = 135;

    /** @return array{private:string, public:string} */
    public static function generate(): array
    {
        $p = self::prime();
        $range = $p->subtract(new BigInteger(3));
        $random = new BigInteger(bin2hex(random_bytes(self::WIDTH)), 16);
        [, $remainder] = $random->divide($range);
        $private = $remainder->add(new BigInteger(2));
        $public = (new BigInteger(2))->modPow($private, $p);

        return [
            'private' => self::encodeDhBase64(self::leftPad($private->toBytes(), self::WIDTH)),
            'public' => self::encodeDhBase64(self::leftPad($public->toBytes(), self::WIDTH)),
        ];
    }

    public static function compute(string $privateEncoded, string $remotePublicEncoded): string
    {
        $privateBytes = self::decodeDhBase64($privateEncoded);
        $publicBytes = self::decodeDhBase64($remotePublicEncoded);
        if (strlen($privateBytes) !== self::WIDTH || strlen($publicBytes) !== self::WIDTH) {
            throw new RuntimeException('Invalid DH1080 key length.');
        }

        $p = self::prime();
        $private = new BigInteger(bin2hex($privateBytes), 16);
        $remote = new BigInteger(bin2hex($publicBytes), 16);
        if ($remote->compare(new BigInteger(2)) < 0 || $remote->compare($p->subtract(new BigInteger(2))) > 0) {
            throw new RuntimeException('DH1080 public key is outside the valid range.');
        }

        $shared = $remote->modPow($private, $p);
        $sharedBytes = self::leftPad($shared->toBytes(), self::WIDTH);
        return self::encodeDhBase64(hash('sha256', $sharedBytes, true));
    }

    private static function prime(): BigInteger
    {
        $raw = base64_decode(self::PRIME_B64, true);
        if ($raw === false || strlen($raw) !== self::WIDTH) {
            throw new RuntimeException('Invalid built-in DH1080 prime.');
        }
        return new BigInteger(bin2hex($raw), 16);
    }

    private static function leftPad(string $bytes, int $length): string
    {
        if (strlen($bytes) > $length) {
            throw new RuntimeException('DH1080 integer exceeds fixed width.');
        }
        return str_pad($bytes, $length, "\0", STR_PAD_LEFT);
    }

    private static function decodeDhBase64(string $encoded): string
    {
        if ($encoded === '' || preg_match('/[^A-Za-z0-9+\/]/', $encoded)) {
            throw new RuntimeException('Invalid DH1080 Base64 value.');
        }
        if (strlen($encoded) % 4 === 1 && str_ends_with($encoded, 'A')) {
            $encoded = substr($encoded, 0, -1);
        }
        $padded = $encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new RuntimeException('Unable to decode DH1080 Base64 value.');
        }
        return $decoded;
    }

    private static function encodeDhBase64(string $bytes): string
    {
        $encoded = base64_encode($bytes);
        return str_contains($encoded, '=') ? rtrim($encoded, '=') : $encoded . 'A';
    }
}
