<?php
/**
 * AI Site Manager — AJAX Endpoint
 * WebJIVE · https://web-jive.com
 *
 * This file handles all client-side AJAX requests from the chat UI.
 * It is web-accessible from the browser at:
 *   /modules/addons/aisitemanager/ajax.php
 *
 * Security layers enforced on every request:
 *   1. WHMCS session validation (must be a logged-in client).
 *   2. CSRF nonce validation (nonce set in _clientarea() session).
 *   3. Account ownership check (client can only access their own account).
 *   4. Path sanitization (delegated to FtpClient and StagingManager).
 *   5. Credentials and API key NEVER returned to the browser.
 *
 * Supported actions (POST parameter: action):
 *   chat    — Send a user message to Claude; returns AI response + operation log.
 *   commit  — Commit staged changes to live site.
 *   discard — Discard all staged changes.
 */

// ---------------------------------------------------------------------------
// Bootstrap WHMCS
// ---------------------------------------------------------------------------
// This file lives at: modules/addons/aisitemanager/ajax.php
// WHMCS root is 3 directory levels up.
$whmcsRoot = dirname(__FILE__, 4);
$initFile  = $whmcsRoot . '/init.php';

if (!file_exists($initFile)) {
    http_response_code(500);
    die(json_encode(['error' => 'WHMCS init.php not found. Check that ajax.php is installed correctly.']));
}

require_once $initFile;

// After init.php, WHMCS session is active and Capsule is available.

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\AiSiteManager\Encryption;
use WHMCS\Module\Addon\AiSiteManager\FtpClient;
use WHMCS\Module\Addon\AiSiteManager\StagingManager;
use WHMCS\Module\Addon\AiSiteManager\ClaudeProxy;

// Autoload lib classes.
spl_autoload_register(function (string $class) {
    $prefix = 'WHMCS\\Module\\Addon\\AiSiteManager\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file          = __DIR__ . '/lib/' . $relativeClass . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Load module config
// ---------------------------------------------------------------------------
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    ajaxError(500, 'Module config.php not found. Copy config.sample.php to config.php.');
}
$config = require $configFile;

// ---------------------------------------------------------------------------
// All responses are JSON
// ---------------------------------------------------------------------------
header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// Security: validate WHMCS client session
// ---------------------------------------------------------------------------
$clientId = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
if (!$clientId || empty($_SESSION['loggedin'])) {
    ajaxError(401, 'You must be logged into your account to use AI Site Manager.');
}

// ---------------------------------------------------------------------------
// Security: CSRF nonce validation
// ---------------------------------------------------------------------------
$nonce         = $_POST['nonce'] ?? '';
$expectedNonce = $_SESSION['aisitemanager_nonce'] ?? '';

if (empty($nonce) || !hash_equals($expectedNonce, $nonce)) {
    ajaxError(403, 'Invalid request token. Please reload the page and try again.');
}

// ---------------------------------------------------------------------------
// Route action
// ---------------------------------------------------------------------------
$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'chat':
            handleChat($clientId, $config);
            break;

        case 'commit':
            handleCommit($clientId, $config);
            break;

        case 'discard':
            handleDiscard($clientId, $config);
            break;

        default:
            ajaxError(400, "Unknown action '{$action}'.");
    }
} catch (\Throwable $e) {
    ajaxError(500, 'Internal error: ' . $e->getMessage() . ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// =============================================================================
// Action handlers
// =============================================================================

/**
 * Handle a chat message.
 *
 * POST params:
 *   message (string) — The user's message text.
 *   nonce   (string) — CSRF nonce.
 *
 * Response JSON:
 *   {
 *     "response":        string  — Claude's reply text.
 *     "operations":      array   — File operations executed (for chat display).
 *     "staging_written": bool    — True if any file was written/deleted/created.
 *     "preview_token":   string  — Fresh preview token (present if staging_written).
 *   }
 */
function handleChat(int $clientId, array $config): void
{
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        ajaxError(400, 'Message cannot be empty.');
    }

    // Load account and verify ownership.
    $account = loadAndVerifyAccount($clientId);

    // Get Anthropic API key from settings (never from POST/GET).
    $apiKey = getApiKey();
    if (empty($apiKey)) {
        ajaxError(503, 'AI Site Manager is not configured yet. Please ask your hosting provider to complete setup.');
    }

    // Load customer details for system prompt.
    $client = Capsule::table('tblclients')
        ->where('id', $clientId)
        ->first(['firstname', 'lastname']);
    $hosting = Capsule::table('tblhosting')
        ->where('id', $account->whmcs_service_id)
        ->first(['domain']);

    $customerName   = ($client ? $client->firstname : 'Customer');
    $customerDomain = ($hosting ? $hosting->domain : '');

    // Load recent chat history for context.
    $chatHistory = Capsule::table('mod_aisitemanager_chat_history')
        ->where('whmcs_client_id', $clientId)
        ->orderBy('id', 'asc')
        ->get(['role', 'message'])
        ->toArray();

    // Decrypt FTP credentials — never leave PHP scope.
    $ftpPassword = Encryption::decrypt($account->ftp_password);

    // Connect FTP.
    $ftp = new FtpClient(
        $account->ftp_host,
        (int)$account->ftp_port,
        $account->ftp_username,
        $ftpPassword,
        (int)($config['ftp_timeout'] ?? 30)
    );
    $ftp->connect();

    $staging = new StagingManager(
        $ftp,
        $config['staging_dir'] ?? '.ai_staging',
        $clientId
    );

    $proxy = new ClaudeProxy(
        $apiKey,
        $ftp,
        $staging,
        (int)($config['max_context_messages'] ?? 20),
        (int)($config['max_read_file_bytes'] ?? 524288)
    );

    // Call Claude.
    $result = $proxy->chat($message, $chatHistory, $customerName, $customerDomain);

    // If staging was written, generate a fresh preview token while FTP is still open.
    // Pass the customer's live domain so ai_preview.php injects the correct <base>
    // tag — assets resolve from the real site, not the server tilde URL.
    $previewToken = null;
    if ($result['staging_written']) {
        $previewToken = $staging->generatePreviewToken(
            (int)($config['preview_token_ttl'] ?? 28800),
            $customerDomain
        );
    }

    $ftp->disconnect();

    // Persist the user message and Claude's response to the DB.
    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_aisitemanager_chat_history')->insert([
        ['whmcs_client_id' => $clientId, 'role' => 'user',      'message' => $message,            'created_at' => $now],
        ['whmcs_client_id' => $clientId, 'role' => 'assistant',  'message' => $result['response'], 'created_at' => $now],
    ]);

    ajaxSuccess([
        'response'        => $result['response'],
        'operations'      => $result['operations'],
        'staging_written' => $result['staging_written'],
        'preview_token'   => $previewToken,
    ]);
}

/**
 * Handle Commit — move staged files to live site.
 *
 * Response JSON:
 *   {
 *     "message": string — Confirmation message.
 *     "log":     array  — Files published and deleted.
 *   }
 */
function handleCommit(int $clientId, array $config): void
{
    $account     = loadAndVerifyAccount($clientId);
    $ftpPassword = Encryption::decrypt($account->ftp_password);

    $ftp = new FtpClient(
        $account->ftp_host,
        (int)$account->ftp_port,
        $account->ftp_username,
        $ftpPassword,
        (int)($config['ftp_timeout'] ?? 30)
    );
    $ftp->connect();

    $staging = new StagingManager($ftp, $config['staging_dir'] ?? '.ai_staging', $clientId);
    $log     = $staging->commit();

    $ftp->disconnect();

    // Add a confirmation message to chat history.
    Capsule::table('mod_aisitemanager_chat_history')->insert([
        'whmcs_client_id' => $clientId,
        'role'            => 'assistant',
        'message'         => 'All your changes have been published to your live website. Your visitors can now see the updates!',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    ajaxSuccess([
        'message' => 'All staged changes have been published to your live site.',
        'log'     => $log,
    ]);
}

/**
 * Handle Discard — delete all staged changes.
 *
 * Response JSON:
 *   { "message": string }
 */
function handleDiscard(int $clientId, array $config): void
{
    $account     = loadAndVerifyAccount($clientId);
    $ftpPassword = Encryption::decrypt($account->ftp_password);

    $ftp = new FtpClient(
        $account->ftp_host,
        (int)$account->ftp_port,
        $account->ftp_username,
        $ftpPassword,
        (int)($config['ftp_timeout'] ?? 30)
    );
    $ftp->connect();

    $staging = new StagingManager($ftp, $config['staging_dir'] ?? '.ai_staging', $clientId);
    $staging->discard();

    $ftp->disconnect();

    // Add a discard confirmation to chat history.
    Capsule::table('mod_aisitemanager_chat_history')->insert([
        'whmcs_client_id' => $clientId,
        'role'            => 'assistant',
        'message'         => 'All staged changes have been discarded. Your live website is unchanged.',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    ajaxSuccess(['message' => 'All staged changes have been discarded.']);
}

// =============================================================================
// Helpers
// =============================================================================

/**
 * Load the AI Site Manager account for $clientId and verify it belongs to them.
 *
 * @param  int    $clientId WHMCS client ID from session.
 * @return object           Capsule row from mod_aisitemanager_accounts.
 */
function loadAndVerifyAccount(int $clientId): object
{
    $account = Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_client_id', $clientId)
        ->where('ai_enabled', 1)
        ->first();

    if (!$account) {
        ajaxError(403, 'AI Site Manager is not enabled for your account.');
    }

    return $account;
}

/**
 * Retrieve the Anthropic API key from the settings table.
 *
 * The key is NEVER passed to the browser — it stays in PHP scope.
 *
 * @return string API key or empty string if not configured.
 */
function getApiKey(): string
{
    $row = Capsule::table('mod_aisitemanager_settings')
        ->where('setting_key', 'api_key')
        ->first(['setting_value']);

    return $row ? trim($row->setting_value) : '';
}

/**
 * Output a JSON error response and exit.
 *
 * @param int    $httpCode HTTP status code.
 * @param string $message  Human-readable error message.
 */
function ajaxError(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Output a JSON success response and exit.
 *
 * @param array $data Response data to JSON-encode.
 */
function ajaxSuccess(array $data): void
{
    http_response_code(200);
    echo json_encode($data);
    exit;
}
