<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use RuntimeException;

final class FiSHKeyVault
{
    private string $keyFile;

    public function __construct(string $baseDirectory)
    {
        $directory = rtrim($baseDirectory, '/') . '/storage';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create FiSH storage directory.');
        }
        $this->keyFile = $directory . '/irc-fish.key';
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->masterKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt FiSH channel key.');
        }
        return base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Invalid encrypted FiSH key record.');
        }
        $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->masterKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt FiSH channel key; vault key may be missing or changed.');
        }
        return $plaintext;
    }

    private function masterKey(): string
    {
        if (!is_file($this->keyFile)) {
            $key = random_bytes(32);
            $handle = @fopen($this->keyFile, 'x');
            if ($handle === false) {
                if (!is_file($this->keyFile)) {
                    throw new RuntimeException('Unable to create FiSH vault key file.');
                }
            } else {
                chmod($this->keyFile, 0600);
                fwrite($handle, $key);
                fflush($handle);
                fclose($handle);
                return $key;
            }
        }
        $key = file_get_contents($this->keyFile);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('FiSH vault key file is missing, unreadable, or invalid.');
        }
        return $key;
    }
}
