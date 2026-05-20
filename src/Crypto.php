<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

/**
 * E2EE шифрование синхронизации.
 *
 * Wire формат: [SALT:16][IV:12][ciphertext][tag:16] → base64
 * KDF: Argon2id (iterations=3, memory=65536 KiB, parallelism=1, hashLength=32)
 * Cipher: AES-256-GCM
 */
class Crypto
{
    /**
     * Зашифровать JSON-строку состояния.
     *
     * @param string $plaintext JSON-строка plaintext
     * @param string $encryptKey Ключ шифрования из Sync Settings
     * @return string Base64-строка wire-формата
     */
    public static function encrypt(string $plaintext, string $encryptKey): string
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);

        $key = self::deriveKey($encryptKey, $salt);

        $tagLen = 16;
        $ciphertext = openssl_encrypt(
            $plaintext,
            'AES-256-GCM',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encrypt failed');
        }

        $wire = $salt . $iv . $ciphertext . $tag;
        return base64_encode($wire);
    }

    /**
     * Расшифровать base64-строку wire-формата.
     *
     * @param string $encryptedBase64 Base64-строка wire-формата
     * @param string $encryptKey Ключ шифрования из Sync Settings
     * @return string JSON-строка plaintext
     */
    public static function decrypt(string $encryptedBase64, string $encryptKey): string
    {
        $padding = strlen($encryptedBase64) % 4;
        if ($padding) {
            $encryptedBase64 .= str_repeat('=', 4 - $padding);
        }

        $encryptedBytes = base64_decode($encryptedBase64, true);
        if ($encryptedBytes === false) {
            throw new \RuntimeException('Base64 decode failed');
        }

        if (strlen($encryptedBytes) < 44) {
            throw new \RuntimeException('Encrypted data too short (need >= 44 bytes for salt+iv+cipher+tag)');
        }

        $salt = substr($encryptedBytes, 0, 16);
        $iv = substr($encryptedBytes, 16, 12);
        $ctAndTag = substr($encryptedBytes, 28);

        if (strlen($ctAndTag) < 17) {
            throw new \RuntimeException('Ciphertext + tag too short');
        }

        $tag = substr($ctAndTag, -16);
        $ciphertext = substr($ctAndTag, 0, -16);

        $key = self::deriveKey($encryptKey, $salt);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'AES-256-GCM',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // Try with tag inside ciphertext (some OpenSSL versions require this)
            $plaintext = openssl_decrypt(
                $ctAndTag,
                'AES-256-GCM',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($plaintext === false) {
                throw new \RuntimeException('AES-256-GCM decrypt failed — wrong encryptKey or corrupted data');
            }
        }

        return $plaintext;
    }

    /**
     * Деривация 256-битного ключа через Argon2id.
     */
    private static function deriveKey(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            32,                          // 256-bit key
            $password,
            $salt,
            3,                             // opslimit (iterations)
            65536 * 1024,                  // memlimit (65536 KiB in bytes)
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }
}
