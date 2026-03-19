<?php
/**
 * AI Site Manager — Encryption Helper
 * WebJIVE · https://web-jive.com
 *
 * Self-contained AES-256-GCM encryption using the module's own encryption_key
 * from config.php.  Does NOT rely on WHMCS's encrypt_db_data() /
 * decrypt_db_data() functions, which are only available in certain WHMCS
 * bootstrap contexts and are unavailable in hook / admin-provision scope.
 *
 * Format stored in the database:
 *   base64( iv[12] . tag[16] . ciphertext[n] )
 *
 * The encryption_key in config.php is a 64-character hex string (32 bytes).
 * It is generated automatically by aisitemanager_activate() on first install.
 */

namespace WHMCS\Module\Addon\AiSiteManager;

class Encryption
{
    private const CIPHER     = 'aes-256-gcm';
    private const IV_LENGTH  = 12;   // 96-bit IV — optimal for GCM
    private const TAG_LENGTH = 16;   // 128-bit authentication tag

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Encrypt a plaintext string for storage in the database.
     *
     * @param  string $value  Plaintext to encrypt.
     * @return string         Base64-encoded ciphertext (IV + tag + ciphertext).
     * @throws \RuntimeException on key or OpenSSL failure.
     */
    public static function encrypt(string $value): string
    {
        $key = self::loadKey();
        $iv  = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ct = openssl_encrypt(
            $value,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ct === false) {
            throw new \RuntimeException(
                'AES-256-GCM encryption failed: ' . openssl_error_string()
            );
        }

        // Pack: IV (12 bytes) | tag (16 bytes) | ciphertext (n bytes)
        return base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypt a value previously encrypted with encrypt().
     *
     * @param  string $encrypted  Base64-encoded ciphertext from the database.
     * @return string             Original plaintext.
     * @throws \RuntimeException  on key, format, or authentication failure.
     */
    public static function decrypt(string $encrypted): string
    {
        $key = self::loadKey();
        $raw = base64_decode($encrypted, true);

        $minLen = self::IV_LENGTH + self::TAG_LENGTH + 1;
        if ($raw === false || strlen($raw) < $minLen) {
            throw new \RuntimeException(
                'Decryption failed: invalid or truncated ciphertext.'
            );
        }

        $iv  = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ct  = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $pt = openssl_decrypt(
            $ct,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($pt === false) {
            throw new \RuntimeException(
                'AES-256-GCM decryption failed (wrong key or corrupted data).'
            );
        }

        return $pt;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load and validate the 32-byte encryption key from config.php.
     *
     * @return string  Raw 32-byte binary key.
     * @throws \RuntimeException if the key is absent or malformed.
     */
    private static function loadKey(): string
    {
        $configPath = __DIR__ . '/../config.php';

        if (!file_exists($configPath)) {
            throw new \RuntimeException(
                'Encryption key unavailable: config.php not found at ' . $configPath
            );
        }

        $config = require $configPath;
        $hex    = $config['encryption_key'] ?? '';

        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException(
                'Encryption key in config.php must be exactly 64 hex characters ' .
                '(32 bytes). Run module activation to regenerate it.'
            );
        }

        return hex2bin($hex);
    }
}
