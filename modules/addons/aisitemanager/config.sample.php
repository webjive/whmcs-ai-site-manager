<?php
/**
 * AI Site Manager — Configuration Reference / Sample
 * WebJIVE · https://web-jive.com
 *
 * NOTE: config.php is auto-generated with secure defaults when you activate
 * the addon in WHMCS (Setup > Addon Modules > AI Site Manager > Activate).
 * You do NOT need to copy this file manually.
 *
 * Use this file as a reference if you need to:
 *   - Restore a lost config.php
 *   - Understand what each setting does
 *   - Manually override defaults (copy to config.php and edit)
 *
 * NEVER commit config.php to version control — it is excluded by .gitignore.
 */

return [

    // -------------------------------------------------------------------------
    // Encryption key used to encrypt/decrypt FTP credentials stored in the DB.
    // Generate a strong random key: php -r "echo bin2hex(random_bytes(32));"
    // Must be exactly 64 hex characters (32 bytes).
    // WARNING: Changing this key after accounts are provisioned will make all
    //          stored FTP passwords unreadable. Rotate carefully.
    // -------------------------------------------------------------------------
    'encryption_key' => 'YOUR_64_HEX_CHARACTER_ENCRYPTION_KEY_HERE',

    // -------------------------------------------------------------------------
    // Default FTP port for new provisioned accounts.
    // Common values: 21 (FTP/FTPS explicit), 990 (FTPS implicit)
    // cPanel servers typically use 21 with explicit TLS.
    // -------------------------------------------------------------------------
    'default_ftp_port' => 21,

    // -------------------------------------------------------------------------
    // FTP connection timeout in seconds.
    // Increase if your hosting servers are geographically distant from WHMCS.
    // -------------------------------------------------------------------------
    'ftp_timeout' => 30,

    // -------------------------------------------------------------------------
    // Name of the staging directory inside public_html.
    // Must start with a dot to be treated as hidden on Linux.
    // Changing this after accounts are provisioned will break existing staging.
    // -------------------------------------------------------------------------
    'staging_dir' => '.ai_staging',

    // -------------------------------------------------------------------------
    // Preview token lifetime in seconds (default: 8 hours).
    // After this time the preview URL expires and the iframe shows an error.
    // -------------------------------------------------------------------------
    'preview_token_ttl' => 28800,

    // -------------------------------------------------------------------------
    // Maximum number of chat messages to pass to Claude per request.
    // Older messages beyond this limit are truncated from the context window.
    // Increase for longer conversations; decrease to save API tokens.
    // -------------------------------------------------------------------------
    'max_context_messages' => 20,

    // -------------------------------------------------------------------------
    // Maximum file size (in bytes) that read_file will transfer.
    // Prevents very large files from consuming excessive memory or API tokens.
    // Default: 512 KB
    // -------------------------------------------------------------------------
    'max_read_file_bytes' => 524288,

    // -------------------------------------------------------------------------
    // WHM API SSL verification.
    // Set to true in production. Set to false only if your WHM server uses a
    // self-signed certificate (common in development/staging environments).
    // -------------------------------------------------------------------------
    'whm_ssl_verify' => false,

];
