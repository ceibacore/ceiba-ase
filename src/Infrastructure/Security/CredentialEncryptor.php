<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Security;

/**
 * AES-256-GCM credential encryptor.
 *
 * GCM provides authenticated encryption — it detects tampering without a
 * separate HMAC. The stored blob format is:
 *
 *   base64( nonce[12 bytes] | tag[16 bytes] | ciphertext )
 *
 * Key setup:
 *   php -r "echo base64_encode(random_bytes(32));"
 *   Add output to .env as GATEWAY_ENCRYPTION_KEY=<value>
 */
final class CredentialEncryptor
{
    private const CIPHER    = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN   = 16;

    private readonly string $key;

    public function __construct(?string $base64Key = null)
    {
        $raw = $base64Key ?? (string) getenv('GATEWAY_ENCRYPTION_KEY');

        if ($raw === '') {
            throw new \RuntimeException(
                'GATEWAY_ENCRYPTION_KEY is not set. ' .
                'Generate one with: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        $decoded = base64_decode($raw, strict: true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException(
                'GATEWAY_ENCRYPTION_KEY must be a base64-encoded 32-byte (256-bit) key.'
            );
        }

        $this->key = $decoded;
    }

    /**
     * Encrypt a credentials array to a storable base64 blob.
     */
    public function encrypt(array $data): string
    {
        $plaintext = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nonce     = random_bytes(self::NONCE_LEN);
        $tag       = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Credential encryption failed: ' . openssl_error_string());
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt a stored blob back to the credentials array.
     *
     * @throws \RuntimeException on tampered or corrupt data
     */
    public function decrypt(string $blob): array
    {
        $raw = base64_decode($blob, strict: true);

        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN + 1) {
            throw new \RuntimeException('Invalid credential blob — corrupt or truncated.');
        }

        $nonce      = substr($raw, 0, self::NONCE_LEN);
        $tag        = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::NONCE_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Credential decryption failed — possible tampering or wrong GATEWAY_ENCRYPTION_KEY.'
            );
        }

        return json_decode($plaintext, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Returns true when the stored value is an encrypted blob (not legacy plaintext JSON).
     * Encrypted blobs are base64-only; plaintext JSON always starts with '{'.
     */
    public function isEncrypted(string $stored): bool
    {
        $trimmed = ltrim($stored);
        return !str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[');
    }
}
