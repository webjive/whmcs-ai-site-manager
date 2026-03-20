<?php
/**
 * AI Site Manager — WHMCS Addon Module
 * WebJIVE · https://web-jive.com
 *
 * Version: 1.0.0
 *
 * Embeds a two-panel website editing interface in the WHMCS client area.
 * Left panel: AI chat where customers make plain-language requests to edit
 * their website files. Right panel: live staging preview before committing.
 *
 * Architecture overview:
 *   - This file: module registration, admin panel output, client area routing.
 *   - hooks.php:  WHMCS lifecycle hooks (auto-provisioning, termination).
 *   - ajax.php:   Browser-facing AJAX endpoint (chat, commit, discard).
 *   - lib/:       FtpClient, StagingManager, ClaudeProxy, Encryption classes.
 *   - templates/clientarea.tpl: Smarty template for the two-panel layout.
 *   - assets/:    CSS and JavaScript for the chat UI.
 *   - deploy/:    ai_preview.php, deployed to customer's public_html on provision.
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

use WHMCS\Database\Capsule;
use WHMCS\Config\Setting;

// Autoload our lib classes.
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

/** Current module version — follows semver. */
define('AISITEMANAGER_VERSION', '1.1.0');

// =============================================================================
// Module metadata
// =============================================================================

/**
 * Return module metadata and configuration descriptor to WHMCS.
 *
 * Fields are intentionally empty here — all settings are stored in the custom
 * mod_aisitemanager_settings table and managed through the admin panel UI,
 * which gives us richer controls (TinyMCE, etc.) than WHMCS field types allow.
 *
 * @return array WHMCS module configuration array.
 */
function aisitemanager_config(): array
{
    return [
        'name'        => 'AI Site Manager',
        'description' => 'Let customers edit their website using plain-language AI chat, with live staging preview before publishing.',
        'version'     => AISITEMANAGER_VERSION,
        'author'      => 'WebJIVE',
        'language'    => 'english',
        'fields'      => [],
    ];
}

// =============================================================================
// Activation
// =============================================================================

/**
 * Create all database tables required by the module.
 *
 * Safe to run multiple times — uses createIfNotExists semantics.
 *
 * @return array WHMCS activation result array.
 */
function aisitemanager_activate(): array
{
    try {
        // ---------------------------------------------------------------------
        // Auto-generate config.php if it does not already exist.
        // This saves the admin from having to manually copy config.sample.php.
        // A cryptographically random encryption_key is generated per-install.
        // ---------------------------------------------------------------------
        $configPath      = __DIR__ . '/config.php';
        $configGenerated = false;

        if (!file_exists($configPath)) {
            $encryptionKey = bin2hex(random_bytes(32)); // 64 hex chars

            $cfg  = "<?php\n";
            $cfg .= "/**\n";
            $cfg .= " * AI Site Manager — Configuration\n";
            $cfg .= " * Auto-generated on activation. Edit as needed.\n";
            $cfg .= " * NEVER commit this file to version control (.gitignore excludes it).\n";
            $cfg .= " */\n\n";
            $cfg .= "return [\n\n";
            $cfg .= "    // Encryption key — auto-generated, unique to this installation.\n";
            $cfg .= "    // WARNING: Changing this after accounts are provisioned makes\n";
            $cfg .= "    // all stored FTP passwords unreadable. Rotate with care.\n";
            $cfg .= "    'encryption_key' => '" . $encryptionKey . "',\n\n";
            $cfg .= "    // Default FTP port (21 = explicit TLS, 990 = implicit TLS).\n";
            $cfg .= "    'default_ftp_port' => 21,\n\n";
            $cfg .= "    // FTP connection timeout in seconds.\n";
            $cfg .= "    'ftp_timeout' => 30,\n\n";
            $cfg .= "    // Staging directory inside public_html (dot prefix = hidden on Linux).\n";
            $cfg .= "    'staging_dir' => '.ai_staging',\n\n";
            $cfg .= "    // Preview token lifetime in seconds (default: 8 hours).\n";
            $cfg .= "    'preview_token_ttl' => 28800,\n\n";
            $cfg .= "    // Max chat messages sent to Claude as context per request.\n";
            $cfg .= "    'max_context_messages' => 20,\n\n";
            $cfg .= "    // Max file size (bytes) the read_file tool will transfer (default: 512 KB).\n";
            $cfg .= "    'max_read_file_bytes' => 524288,\n\n";
            $cfg .= "    // Set true if your WHM server has a valid SSL certificate.\n";
            $cfg .= "    'whm_ssl_verify' => false,\n\n";
            $cfg .= "];\n";

            if (file_put_contents($configPath, $cfg) === false) {
                return [
                    'status'      => 'error',
                    'description' => 'Activation failed: could not write config.php to ' . __DIR__ .
                                     '. Check directory permissions and try again.',
                ];
            }

            $configGenerated = true;
        }

        // mod_aisitemanager_accounts — one row per provisioned hosting account.
        if (!Capsule::schema()->hasTable('mod_aisitemanager_accounts')) {
            Capsule::schema()->create('mod_aisitemanager_accounts', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_client_id')->unsigned()->unique();
                $table->integer('whmcs_service_id')->unsigned();
                $table->string('cpanel_username', 64);
                $table->string('ftp_username', 128);
                $table->text('ftp_password');          // Encrypted via WHMCS.
                $table->string('ftp_host', 255);
                $table->integer('ftp_port')->default(21);
                $table->boolean('ai_enabled')->default(false);
                $table->boolean('staging_active')->default(false);
                $table->string('site_mode', 20)->default('construction');
                $table->string('preview_token', 64)->nullable();
                $table->dateTime('preview_token_expiry')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // mod_aisitemanager_chat_history — full conversation log per client.
        if (!Capsule::schema()->hasTable('mod_aisitemanager_chat_history')) {
            Capsule::schema()->create('mod_aisitemanager_chat_history', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_client_id')->unsigned();
                $table->enum('role', ['user', 'assistant']);
                $table->text('message');
                $table->timestamp('created_at')->useCurrent();
                $table->index('whmcs_client_id');
            });
        }

        // mod_aisitemanager_settings — global key/value settings.
        if (!Capsule::schema()->hasTable('mod_aisitemanager_settings')) {
            Capsule::schema()->create('mod_aisitemanager_settings', function ($table) {
                $table->increments('id');
                $table->string('setting_key', 64)->unique();
                $table->longText('setting_value');
                $table->timestamp('updated_at')->useCurrent();
            });

            // Seed default settings.
            Capsule::table('mod_aisitemanager_settings')->insert([
                ['setting_key' => 'api_key',               'setting_value' => ''],
                ['setting_key' => 'header_wysiwyg_content', 'setting_value' =>
                    '<p>Welcome to <strong>AI Site Manager</strong>! Use the chat below to make changes to your website in plain English.</p>' .
                    '<p>All changes go to a <strong>staging area</strong> first — click <strong>Commit</strong> to publish, or <strong>Discard</strong> to cancel.</p>'],
                ['setting_key' => 'linked_products',        'setting_value' => '[]'],
            ]);
        }

        $configNote = $configGenerated
            ? ' config.php was auto-generated with a unique encryption key.'
            : ' Existing config.php was preserved.';

        return [
            'status'      => 'success',
            'description' => 'AI Site Manager v' . AISITEMANAGER_VERSION . ' installed.' . $configNote .
                             ' Enter your Anthropic API key in the Settings tab to get started.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

// =============================================================================
// Deactivation
// =============================================================================

/**
 * Drop all module tables on deactivation.
 *
 * WARNING: This permanently deletes all chat history and account records.
 * WHMCS prompts for confirmation before deactivating an addon.
 *
 * @return array WHMCS deactivation result array.
 */
function aisitemanager_deactivate(): array
{
    try {
        Capsule::schema()->dropIfExists('mod_aisitemanager_chat_history');
        Capsule::schema()->dropIfExists('mod_aisitemanager_accounts');
        Capsule::schema()->dropIfExists('mod_aisitemanager_settings');

        return [
            'status'      => 'success',
            'description' => 'AI Site Manager uninstalled. All module tables removed.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Deactivation failed: ' . $e->getMessage(),
        ];
    }
}

// =============================================================================
// Upgrade
// =============================================================================

/**
 * Run version-specific database migrations on module upgrade.
 *
 * $vars['version'] is the previously installed version string.
 * Add migration blocks here as new versions are released.
 *
 * @param array $vars WHMCS-supplied vars including 'version'.
 */
function aisitemanager_upgrade(array $vars): void
{
    $prev = $vars['version'] ?? '0';

    if (version_compare($prev, '1.1.0', '<')) {
        if (!Capsule::schema()->hasColumn('mod_aisitemanager_accounts', 'site_mode')) {
            Capsule::schema()->table('mod_aisitemanager_accounts', function ($table) {
                $table->string('site_mode', 20)->default('construction')->after('staging_active');
            });
        }
    }
}

// =============================================================================
// API key validation helper
// =============================================================================

/**
 * Test an Anthropic API key by hitting the token-count endpoint.
 *
 * Uses POST /v1/messages/count_tokens, which validates authentication without
 * generating output tokens (zero cost). Returns a structured result so the
 * caller can display a precise error and a clear fix instruction.
 *
 * @param  string $apiKey The API key to test.
 * @return array  {
 *     ok:    bool         — true if key is working (or rate-limited but valid).
 *     error: string|null  — human-readable problem description.
 *     fix:   string|null  — HTML fix instruction (trusted string, may contain <a>).
 * }
 */
function aisitemanager_testApiKey(string $apiKey): array
{
    $payload = json_encode([
        'model'    => 'claude-3-haiku-20240307',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages/count_tokens');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '          . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: token-counting-2024-11-01',
        ],
    ]);

    $body     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Network-level failure — server cannot reach Anthropic.
    if ($curlErr) {
        return [
            'ok'    => false,
            'error' => 'Could not connect to api.anthropic.com: ' . htmlspecialchars($curlErr),
            'fix'   => 'Ensure your WHMCS server can make outbound HTTPS requests on port 443. '
                     . 'Check firewall rules and that PHP\'s cURL is compiled with SSL support.',
        ];
    }

    if ($httpCode === 200) {
        return ['ok' => true, 'error' => null, 'fix' => null];
    }

    // Parse the error message from Anthropic's JSON response.
    $decoded = json_decode($body, true);
    $apiMsg  = isset($decoded['error']['message'])
        ? htmlspecialchars($decoded['error']['message'])
        : null;

    switch ($httpCode) {
        case 401:
            return [
                'ok'    => false,
                'error' => 'Invalid API key' . ($apiMsg ? ': ' . $apiMsg : '.'),
                'fix'   => 'Log into <a href="https://console.anthropic.com/settings/keys" '
                         . 'target="_blank" rel="noopener">console.anthropic.com → API Keys</a> '
                         . 'and verify the key has not been revoked. Make sure you copied the full key '
                         . '(it starts with <code>sk-ant-</code>).',
            ];

        case 403:
            return [
                'ok'    => false,
                'error' => 'API key lacks permission' . ($apiMsg ? ': ' . $apiMsg : '.'),
                'fix'   => 'Your Anthropic workspace may be suspended or your plan may not include '
                         . 'API access. Check your account status at '
                         . '<a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>.',
            ];

        case 429:
            // Rate-limited — key is valid but the account has hit a usage ceiling.
            return [
                'ok'    => true,
                'error' => 'API key is valid but currently rate-limited' . ($apiMsg ? ': ' . $apiMsg : '.'),
                'fix'   => 'Your key works. The rate limit will reset automatically. Check your usage '
                         . 'and limits at <a href="https://console.anthropic.com/settings/limits" '
                         . 'target="_blank" rel="noopener">console.anthropic.com → Limits</a>.',
            ];

        default:
            return [
                'ok'    => false,
                'error' => 'Anthropic API returned HTTP ' . $httpCode . ($apiMsg ? ': ' . $apiMsg : '.'),
                'fix'   => 'This may be a temporary Anthropic service issue. '
                         . 'Check <a href="https://status.anthropic.com" target="_blank" rel="noopener">status.anthropic.com</a> '
                         . 'and try saving the settings again in a moment.',
            ];
    }
}

// =============================================================================
// Admin panel output
// =============================================================================

/**
 * Render the admin panel for this addon module.
 *
 * WHMCS calls this function and expects it to ECHO HTML (not return it).
 * Access via: Setup > Addon Modules > AI Site Manager > Configure.
 *
 * The panel has two tabs:
 *   Accounts — list all active hosting accounts with provision/enable controls.
 *   Settings — Anthropic API key and header WYSIWYG content.
 *
 * @param array $vars WHMCS-supplied module variables.
 */
function aisitemanager_output(array $vars): void
{
    $moduleLink = $vars['modulelink'];
    $alerts     = [];

    // -------------------------------------------------------------------------
    // Handle POST actions
    // -------------------------------------------------------------------------
    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        switch ($action) {

            // Save API key and WYSIWYG content, then verify the key works.
            case 'save_settings':
                $apiKey  = trim($_POST['api_key'] ?? '');
                $wysiwyg = $_POST['header_wysiwyg_content'] ?? '';

                // Guard against double-encoding: if TinyMCE didn't sync before submit,
                // the textarea POST value contains HTML entities. Decode them so the DB
                // always holds clean HTML, not escaped markup.
                if (strpos($wysiwyg, '&lt;') !== false) {
                    $wysiwyg = html_entity_decode($wysiwyg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                // Linked products: multi-select sends an array (or nothing if none selected).
                $postedProductIds = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['linked_products'] ?? []))
                )));

                // Always persist all values first, even if the API key is wrong —
                // so the admin doesn't have to re-enter anything on failure.
                Capsule::table('mod_aisitemanager_settings')
                    ->where('setting_key', 'api_key')
                    ->update(['setting_value' => $apiKey]);

                Capsule::table('mod_aisitemanager_settings')
                    ->where('setting_key', 'header_wysiwyg_content')
                    ->update(['setting_value' => $wysiwyg]);

                Capsule::table('mod_aisitemanager_settings')
                    ->updateOrInsert(
                        ['setting_key' => 'linked_products'],
                        ['setting_value' => json_encode($postedProductIds)]
                    );

                // Test the API key if one was provided.
                if ($apiKey !== '') {
                    $test = aisitemanager_testApiKey($apiKey);

                    if ($test['ok'] && $test['error'] === null) {
                        // Key is working perfectly.
                        $alerts[] = [
                            'type' => 'success',
                            'msg'  => '&#10003; Settings saved and Anthropic API key verified successfully.',
                        ];
                    } elseif ($test['ok'] && $test['error'] !== null) {
                        // Key is valid but something minor is off (e.g. rate-limited).
                        $alerts[] = [
                            'type' => 'warning',
                            'msg'  => 'Settings saved. Note: ' . $test['error']
                                    . ($test['fix'] ? ' <br><strong>Info:</strong> ' . $test['fix'] : ''),
                        ];
                    } else {
                        // Key check failed — settings saved but AI will not work yet.
                        $alerts[] = [
                            'type' => 'danger',
                            'msg'  => 'Settings saved, but the API key check failed: ' . $test['error']
                                    . ($test['fix'] ? '<br><strong>How to fix:</strong> ' . $test['fix'] : ''),
                        ];
                    }
                } else {
                    // No key provided.
                    $alerts[] = [
                        'type' => 'warning',
                        'msg'  => 'Settings saved. No Anthropic API key entered — AI chat will not '
                                . 'work until you add one.',
                    ];
                }
                break;

            // Toggle AI enabled/disabled for a single account.
            case 'toggle_ai':
                $targetClientId = (int)($_POST['client_id'] ?? 0);
                $enable         = (int)($_POST['enable'] ?? 0);
                if ($targetClientId) {
                    Capsule::table('mod_aisitemanager_accounts')
                        ->where('whmcs_client_id', $targetClientId)
                        ->update(['ai_enabled' => $enable]);
                    $alerts[] = ['type' => 'success', 'msg' => 'AI status updated.'];
                }
                break;

            // Manually provision a hosting account.
            case 'provision':
                $targetServiceId = (int)($_POST['service_id'] ?? 0);
                $targetClientId  = (int)($_POST['client_id'] ?? 0);
                if ($targetServiceId && $targetClientId) {
                    $configFile = __DIR__ . '/config.php';
                    if (!file_exists($configFile)) {
                        $alerts[] = ['type' => 'danger', 'msg' => 'config.php not found. Please set it up before provisioning.'];
                    } else {
                        try {
                            $config = require $configFile;
                            aisitemanager_provisionAccount($targetServiceId, $targetClientId, $config);
                            $alerts[] = ['type' => 'success', 'msg' => 'Account provisioned successfully.'];
                        } catch (\Exception $e) {
                            $alerts[] = ['type' => 'danger', 'msg' => 'Provisioning failed: ' . htmlspecialchars($e->getMessage())];
                        }
                    }
                }
                break;

            // Deprovision (remove DB record for account).
            case 'deprovision':
                $targetClientId = (int)($_POST['client_id'] ?? 0);
                if ($targetClientId) {
                    aisitemanager_deprovisionAccount($targetClientId);
                    $alerts[] = ['type' => 'success', 'msg' => 'Account deprovisioned.'];
                }
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Load data for display
    // -------------------------------------------------------------------------
    $totalAccounts   = Capsule::table('mod_aisitemanager_accounts')->count();
    $enabledAccounts = Capsule::table('mod_aisitemanager_accounts')->where('ai_enabled', 1)->count();

    // All active hosting accounts joined with AI status (LEFT JOIN — shows
    // accounts not yet provisioned so admin can provision them).
    $accounts = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->leftJoin('mod_aisitemanager_accounts as a', 'a.whmcs_service_id', '=', 'h.id')
        ->select(
            'h.id as service_id', 'h.userid as client_id', 'h.username as cpanel_user',
            'h.domain', 'c.firstname', 'c.lastname',
            'a.id as ai_id', 'a.ai_enabled', 'a.staging_active'
        )
        ->where('h.domainstatus', 'Active')
        ->orderBy('c.lastname')
        ->orderBy('c.firstname')
        ->get();

    // Current settings values.
    $settings = [];
    foreach (Capsule::table('mod_aisitemanager_settings')->get() as $row) {
        $settings[$row->setting_key] = $row->setting_value;
    }

    $currentApiKey  = $settings['api_key'] ?? '';
    $currentWysiwyg = $settings['header_wysiwyg_content'] ?? '';

    // Self-heal: if the WYSIWYG value was saved with double-encoded HTML entities
    // (e.g. &lt;p&gt; instead of <p>), decode it once and persist the clean value.
    if (!empty($currentWysiwyg) && strpos($currentWysiwyg, '&lt;') !== false) {
        $currentWysiwyg = html_entity_decode($currentWysiwyg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        Capsule::table('mod_aisitemanager_settings')
            ->where('setting_key', 'header_wysiwyg_content')
            ->update(['setting_value' => $currentWysiwyg]);
    }

    // Linked products — seed the setting if missing (existing installs pre-dating this feature).
    if (!array_key_exists('linked_products', $settings)) {
        Capsule::table('mod_aisitemanager_settings')->insert([
            'setting_key'   => 'linked_products',
            'setting_value' => '[]',
        ]);
        $settings['linked_products'] = '[]';
    }
    $linkedProductIds = json_decode($settings['linked_products'], true);
    if (!is_array($linkedProductIds)) { $linkedProductIds = []; }

    // All WHMCS products (grouped by product group) for the linked-products selector.
    $allProducts = Capsule::table('tblproducts as p')
        ->leftJoin('tblproductgroups as g', 'g.id', '=', 'p.gid')
        ->select('p.id', 'p.name', 'g.name as group_name')
        ->orderBy('g.name')
        ->orderBy('p.name')
        ->get();

    // Determine active tab.
    $activeTab = ($_GET['tab'] ?? 'accounts') === 'settings' ? 'settings' : 'accounts';

    // -------------------------------------------------------------------------
    // Output HTML
    // -------------------------------------------------------------------------
    ?>
    <!-- ===== AI SITE MANAGER ADMIN PANEL ===== -->
    <style>
        .aisitemanager-admin { font-family: inherit; }
        .aisitemanager-stat-card { background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:16px 24px; display:inline-block; margin-right:12px; margin-bottom:16px; min-width:140px; text-align:center; }
        .aisitemanager-stat-card .stat-number { font-size:2rem; font-weight:700; color:#2d6a4f; }
        .aisitemanager-stat-card .stat-label  { font-size:.85rem; color:#6c757d; }
        .aisitemanager-admin .nav-tabs { margin-bottom:20px; }
        .aisitemanager-admin table { width:100%; border-collapse:collapse; }
        .aisitemanager-admin th, .aisitemanager-admin td { padding:10px 12px; border-bottom:1px solid #dee2e6; vertical-align:middle; }
        .aisitemanager-admin th { background:#f1f3f4; font-weight:600; }
        .badge-enabled  { background:#28a745; color:#fff; padding:3px 8px; border-radius:4px; font-size:.8rem; }
        .badge-disabled { background:#6c757d; color:#fff; padding:3px 8px; border-radius:4px; font-size:.8rem; }
        .badge-staging  { background:#fd7e14; color:#fff; padding:3px 8px; border-radius:4px; font-size:.8rem; }
    </style>

    <div class="aisitemanager-admin">

        <!-- Alerts -->
        <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> alert-dismissible">
                <?= $alert['msg'] ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endforeach; ?>

        <h2>AI Site Manager <small class="text-muted">v<?= AISITEMANAGER_VERSION ?></small></h2>

        <!-- Stats -->
        <div class="aisitemanager-stats" style="margin-bottom:20px;">
            <div class="aisitemanager-stat-card">
                <div class="stat-number"><?= (int)$totalAccounts ?></div>
                <div class="stat-label">Total Accounts</div>
            </div>
            <div class="aisitemanager-stat-card">
                <div class="stat-number"><?= (int)$enabledAccounts ?></div>
                <div class="stat-label">AI Enabled</div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs">
            <li class="<?= $activeTab === 'accounts' ? 'active' : '' ?>">
                <a href="<?= htmlspecialchars($moduleLink) ?>&tab=accounts">Accounts</a>
            </li>
            <li class="<?= $activeTab === 'settings' ? 'active' : '' ?>">
                <a href="<?= htmlspecialchars($moduleLink) ?>&tab=settings">Settings</a>
            </li>
        </ul>

        <!-- ===================== ACCOUNTS TAB ===================== -->
        <?php if ($activeTab === 'accounts'): ?>
        <div class="tab-content-accounts">
            <?php if ($accounts->isEmpty()): ?>
                <p class="text-muted">No active hosting accounts found.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Domain</th>
                        <th>cPanel User</th>
                        <th>AI Status</th>
                        <th>Staging</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $acct): ?>
                    <tr>
                        <td>
                            <a href="clientsummary.php?userid=<?= (int)$acct->client_id ?>">
                                <?= htmlspecialchars($acct->firstname . ' ' . $acct->lastname) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($acct->domain) ?></td>
                        <td><code><?= htmlspecialchars($acct->cpanel_user) ?></code></td>
                        <td>
                            <?php if ($acct->ai_id): ?>
                                <?php if ($acct->ai_enabled): ?>
                                    <span class="badge-enabled">Enabled</span>
                                <?php else: ?>
                                    <span class="badge-disabled">Disabled</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Not provisioned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($acct->ai_id && $acct->staging_active): ?>
                                <span class="badge-staging">Active</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($acct->ai_id): ?>
                                <!-- Toggle AI enable/disable -->
                                <form method="post" action="<?= htmlspecialchars($moduleLink) ?>&action=toggle_ai" style="display:inline;">
                                    <input type="hidden" name="client_id" value="<?= (int)$acct->client_id ?>">
                                    <input type="hidden" name="enable" value="<?= $acct->ai_enabled ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-xs btn-<?= $acct->ai_enabled ? 'warning' : 'success' ?>">
                                        <?= $acct->ai_enabled ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <!-- Deprovision -->
                                <form method="post" action="<?= htmlspecialchars($moduleLink) ?>&action=deprovision" style="display:inline;"
                                      onsubmit="return confirm('Remove AI Site Manager provisioning for this account? The FTP sub-account on the server is NOT deleted — only the WHMCS record is removed.');">
                                    <input type="hidden" name="client_id" value="<?= (int)$acct->client_id ?>">
                                    <button type="submit" class="btn btn-xs btn-danger">Deprovision</button>
                                </form>
                            <?php else: ?>
                                <!-- Provision -->
                                <form method="post" action="<?= htmlspecialchars($moduleLink) ?>&action=provision" style="display:inline;">
                                    <input type="hidden" name="service_id" value="<?= (int)$acct->service_id ?>">
                                    <input type="hidden" name="client_id"  value="<?= (int)$acct->client_id ?>">
                                    <button type="submit" class="btn btn-xs btn-primary">Provision</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ===================== SETTINGS TAB ===================== -->
        <?php else: ?>
        <div class="tab-content-settings">
            <form method="post" action="<?= htmlspecialchars($moduleLink) ?>&action=save_settings&tab=settings"
                  onsubmit="if(typeof tinymce!=='undefined'){tinymce.triggerSave();}">

                <!-- Linked Products -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label><strong>Linked Products</strong></label>
                    <p class="text-muted" style="font-size:.9em;">
                        Select which hosting products automatically provision AI Site Manager when a new
                        account is created. Accounts on other products can still be provisioned manually
                        from the <a href="<?= htmlspecialchars($moduleLink) ?>&tab=accounts">Accounts</a> tab.
                        If no products are selected, auto-provisioning is <strong>disabled</strong>.
                    </p>
                    <?php if ($allProducts->isEmpty()): ?>
                        <p class="text-muted">No WHMCS products found.</p>
                    <?php else: ?>
                    <select name="linked_products[]" id="linked_products" multiple
                            class="form-control"
                            style="max-width:520px; height:<?= min(max(count((array)$allProducts) * 28 + 8, 100), 220) ?>px;">
                        <?php
                        $currentGroup = null;
                        foreach ($allProducts as $product):
                            $groupLabel = $product->group_name ?: 'Ungrouped';
                            if ($groupLabel !== $currentGroup):
                                if ($currentGroup !== null): ?></optgroup><?php endif;
                                $currentGroup = $groupLabel; ?>
                                <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                            <?php endif; ?>
                            <option value="<?= (int)$product->id ?>"
                                <?= in_array((int)$product->id, $linkedProductIds) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($product->name) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentGroup !== null): ?></optgroup><?php endif; ?>
                    </select>
                    <p class="text-muted" style="font-size:.82em; margin-top:6px;">
                        Hold <kbd>Ctrl</kbd> (Windows/Linux) or <kbd>Cmd</kbd> (Mac) to select multiple.
                        <?php if (empty($linkedProductIds)): ?>
                        &nbsp;<span class="label label-warning" style="font-size:.9em;">Auto-provisioning currently OFF — no products linked.</span>
                        <?php else: ?>
                        &nbsp;<span class="label label-success" style="font-size:.9em;"><?= count($linkedProductIds) ?> product<?= count($linkedProductIds) !== 1 ? 's' : '' ?> linked.</span>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- WYSIWYG Header Content -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="header_wysiwyg_content"><strong>Client Area Header Content</strong></label>
                    <p class="text-muted" style="font-size:.9em;">
                        Displayed at the top of the AI chat panel for all customers. Use it for
                        instructions, getting-started tips, and links to tutorial videos.
                        Supports text formatting and hyperlinks.
                    </p>
                    <textarea id="header_wysiwyg_content" name="header_wysiwyg_content"
                              class="form-control" rows="8" style="max-width:800px;"><?= htmlspecialchars($currentWysiwyg) ?></textarea>
                </div>

                <!-- Anthropic API Key -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="api_key"><strong>Anthropic API Key</strong></label>
                    <p class="text-muted" style="font-size:.9em;">
                        Your Anthropic API key from
                        <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>.
                        Stored in the database. Never exposed to the client browser.
                    </p>
                    <input type="password" id="api_key" name="api_key"
                           class="form-control" style="max-width:480px;"
                           value="<?= htmlspecialchars($currentApiKey) ?>"
                           placeholder="sk-ant-…"
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary">Save Settings</button>

            </form>
        </div>

        <!-- TinyMCE initialization for the WYSIWYG editor -->
        <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
        <script>
        tinymce.init({
            selector:  '#header_wysiwyg_content',
            height:    320,
            menubar:   false,
            plugins:   'link lists',
            toolbar:   'bold italic underline | bullist numlist | link | removeformat',
            skin:      'oxide',
            promotion: false,
            branding:  false
        });
        </script>
        <?php endif; ?>

    </div><!-- .aisitemanager-admin -->
    <?php
}

// =============================================================================
// Client area
// =============================================================================

/**
 * Render the AI Site Manager page in the WHMCS client area.
 *
 * Returns a data array to WHMCS which is merged with the Smarty template
 * variables and rendered via templates/clientarea.tpl.
 *
 * Security: requirelogin => true ensures WHMCS redirects unauthenticated
 * visitors before this function is called.
 *
 * @param array $vars WHMCS module variables.
 * @return array      Template data array.
 */
function aisitemanager_clientarea(array $vars): array
{
    // Use WHMCS-provided client ID from $vars — more reliable than $_SESSION['uid']
    // because WHMCS populates this before calling our function with requirelogin.
    $clientId = (int)($vars['clientsdetails']['id'] ?? $_SESSION['uid'] ?? 0);

    // -------------------------------------------------------------------------
    // AJAX sub-request routing.
    //
    // AJAX calls are sent to index.php?m=aisitemanager (not to ajax.php).
    // This ensures WHMCS fully authenticates the session before our code runs,
    // which is more reliable than trying to resume the session in a standalone
    // ajax.php via require_once init.php.
    //
    // When WHMCS routes a POST to this function, requirelogin => true has already
    // been enforced, so $clientId is guaranteed to be the logged-in client.
    // -------------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        aisitemanager_dispatchAjax($clientId);
        // aisitemanager_dispatchAjax() always calls exit — line below unreachable.
    }

    // -------------------------------------------------------------------------
    // Look up the client's AI Site Manager account.
    // -------------------------------------------------------------------------
    $account = Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_client_id', $clientId)
        ->first();

    // If no account or AI is disabled, show "not available" message.
    if (!$account || !$account->ai_enabled) {
        return [
            'pagetitle'    => 'AI Site Manager',
            'breadcrumb'   => ['index.php?m=aisitemanager' => 'AI Site Manager'],
            'templatefile' => 'clientarea',
            'requirelogin' => true,
            'vars'         => ['not_available' => true],
        ];
    }

    // -------------------------------------------------------------------------
    // Staging reconciliation — sync DB flag with actual disk state.
    // Handles JetBackup restores and any other out-of-band disk changes.
    // -------------------------------------------------------------------------
    $configFile = __DIR__ . '/config.php';
    $config     = file_exists($configFile) ? require $configFile : [];
    $stagingDir = $config['staging_dir'] ?? '.ai_staging';

    $stagingActive = (bool)$account->staging_active;

    try {
        $encryptedPass = $account->ftp_password;
        $ftpPassword   = \WHMCS\Module\Addon\AiSiteManager\Encryption::decrypt($encryptedPass);

        $ftp = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
            $account->ftp_host,
            (int)$account->ftp_port,
            $account->ftp_username,
            $ftpPassword,
            (int)($config['ftp_timeout'] ?? 30)
        );
        $ftp->connect();

        $staging       = new \WHMCS\Module\Addon\AiSiteManager\StagingManager($ftp, $stagingDir, $clientId);
        $stagingActive = $staging->reconcile(); // Updates DB if mismatch found.

        $ftp->disconnect();
    } catch (\Exception $e) {
        // Reconciliation failure is non-fatal — use the DB value as fallback.
        // The UI will still load; FTP errors will surface when the customer chats.
        logActivity("AI Site Manager: Reconciliation failed for client #{$clientId}: " . $e->getMessage());
    }

    // -------------------------------------------------------------------------
    // Generate a CSRF nonce for this page load.
    // -------------------------------------------------------------------------
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['aisitemanager_nonce'] = $nonce;

    // -------------------------------------------------------------------------
    // Build the site URL and preview URL.
    //
    // We serve the preview via the server's hostname using cPanel's tilde URL:
    //   https://server.hostname/~cpaneluser/
    //
    // This bypasses DNS completely — the domain doesn't need to be pointed at
    // the server yet. The client's files are served directly from disk.
    //
    // IMPORTANT: $siteDomain must be resolved BEFORE generatePreviewToken() is
    // called below so it can be stored in the token file. ai_preview.php reads
    // it back to inject the correct <base> tag (e.g. giraffetree.com) instead
    // of the server tilde hostname (earth1.webjive.net).
    // -------------------------------------------------------------------------
    $hosting = Capsule::table('tblhosting')
        ->where('id', $account->whmcs_service_id)
        ->first(['domain']);   // 'serverid' column name varies by WHMCS version — avoided here.

    $siteDomain = $hosting ? (string)$hosting->domain : '';
    $siteUrl    = $siteDomain ? 'https://' . $siteDomain : '#';
    $siteMode   = (string)($account->site_mode ?? 'construction');

    // -------------------------------------------------------------------------
    // Always generate a preview token — the token carries site_mode so
    // ai_preview.php knows whether to serve files directly (construction) or
    // cURL-proxy against the live domain (production), regardless of whether
    // any files are currently staged.
    //
    // Reuse the existing token only if it is still valid AND was generated
    // for the current site_mode (mode change requires a fresh token so the
    // token file on disk reflects the new mode immediately).
    // -------------------------------------------------------------------------
    $previewToken = null;
    $tokenStillValid = $account->preview_token
        && $account->preview_token_expiry
        && strtotime($account->preview_token_expiry) > time();

    // We can only reuse the token if the mode hasn't changed.  Since we don't
    // store the mode alongside the DB token, we always regenerate in production
    // mode (cheap insurance) and reuse in construction mode when still valid.
    $canReuseToken = $tokenStillValid && $siteMode === 'construction';

    if ($canReuseToken) {
        $previewToken = $account->preview_token;
    } else {
        try {
            $ftpPassword2 = \WHMCS\Module\Addon\AiSiteManager\Encryption::decrypt($account->ftp_password);
            $ftp2 = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
                $account->ftp_host, (int)$account->ftp_port,
                $account->ftp_username, $ftpPassword2,
                (int)($config['ftp_timeout'] ?? 30)
            );
            $ftp2->connect();
            $staging2     = new \WHMCS\Module\Addon\AiSiteManager\StagingManager($ftp2, $stagingDir, $clientId);
            $previewToken = $staging2->generatePreviewToken(
                (int)($config['preview_token_ttl'] ?? 28800),
                $siteDomain,
                $siteMode
            );
            $ftp2->disconnect();
        } catch (\Exception $e) {
            logActivity("AI Site Manager: Preview token generation failed for client #{$clientId}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Load chat history and settings.
    // -------------------------------------------------------------------------
    // Capsule returns stdClass objects; Smarty dot-notation needs plain arrays.
    $chatHistory = array_map(
        fn($row) => (array)$row,
        Capsule::table('mod_aisitemanager_chat_history')
            ->where('whmcs_client_id', $clientId)
            ->orderBy('id', 'asc')
            ->get(['role', 'message', 'created_at'])
            ->toArray()
    );

    $headerContent = Capsule::table('mod_aisitemanager_settings')
        ->where('setting_key', 'header_wysiwyg_content')
        ->value('setting_value') ?? '';

    // Build the tilde URL directly from the account record.
    // ftp_host stores the server hostname (e.g. earth1.webjive.net) and
    // cpanel_username stores the 8-char truncated cPanel user (e.g. mmisolut).
    // This bypasses DNS completely so the preview works even when the domain
    // isn't pointed at this server yet.
    $serverHostname = $account->ftp_host        ?? '';
    $cpanelUser     = $account->cpanel_username ?? '';

    // Base URL for direct file access: https://server/~cpaneluser/
    // Falls back to the domain URL if account data is missing.
    $previewBase = ($serverHostname && $cpanelUser)
        ? 'https://' . $serverHostname . '/~' . $cpanelUser . '/'
        : $siteUrl . '/';

    // Preview URL strategy: always route through ai_preview.php proxy.
    //
    //   Construction (any)   → proxy serves files directly from the tilde path
    //                          (domain may not exist yet).
    //   Production + staging → proxy fetches live HTML via cURL and overlays
    //                          staged changes; strips crossorigin/type=module
    //                          attributes to prevent CORS failures.
    //   Production + no staging → proxy fetches live HTML via cURL and serves
    //                          it with CSS fixes injected (negative z-index
    //                          overrides ensure hero background layers render
    //                          correctly inside the iframe).
    //
    // NOTE: We previously loaded the live domain directly (no proxy) for the
    // production+no-staging case to avoid CORS overhead.  The direct-iframe
    // approach caused a white gap in the hero section because Tailwind's
    // isolation:isolate + -z-10 combination renders background layers below
    // the stacking context in some iframe/sandbox configurations.  The proxy
    // injecting [class*="-z-"]{z-index:0!important} fixes this reliably.
    $proxyUrl = $previewToken
        ? $previewBase . 'ai_preview.php?t=' . urlencode($previewToken)
        : $previewBase;

    $previewUrl = $proxyUrl;

    // Shareable URL — always tilde proxy path so it works regardless of DNS.
    $shareablePreviewUrl = $previewToken
        ? $previewBase . 'ai_preview.php?t=' . urlencode($previewToken)
        : '';

    // URL to the ajax.php endpoint.
    // IMPORTANT: Use the actual request host ($_SERVER['HTTP_HOST']) rather than
    // the WHMCS SystemURL setting. If SystemURL is configured as 'example.com'
    // but the client browses via 'www.example.com', the origins differ and
    // fetch() will not send the session cookie → 401 "not logged in".
    // Using HTTP_HOST guarantees the ajax URL is always same-origin.
    $systemUrl   = Setting::getValue('SystemURL');
    $reqScheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $reqHost     = $_SERVER['HTTP_HOST'] ?? parse_url($systemUrl, PHP_URL_HOST);
    // Preserve the path prefix in case WHMCS is installed in a subdirectory.
    $sysPath     = rtrim(parse_url($systemUrl, PHP_URL_PATH) ?? '', '/');
    $reqBase     = $reqScheme . '://' . $reqHost . $sysPath;
    // Route AJAX through the WHMCS addon module entry point so that WHMCS
    // handles session + auth before our code runs (no init.php hacks needed).
    $ajaxUrl     = $reqBase . '/index.php?m=aisitemanager';

    // -------------------------------------------------------------------------
    // Enqueue module assets.
    // -------------------------------------------------------------------------
    $assetBase = $reqBase . '/modules/addons/aisitemanager/assets';

    // Inject CSS and JS via template vars (template adds them to <head>/<body>).
    return [
        'pagetitle'    => 'AI Site Manager',
        'breadcrumb'   => ['index.php?m=aisitemanager' => 'AI Site Manager'],
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'vars'         => [
            'not_available'  => false,
            'staging_active' => $stagingActive,
            'preview_token'  => $previewToken,
            'preview_url'    => $previewUrl,
            'preview_base'   => $previewBase,   // Tilde URL root — JS uses this to build token URLs.
            'site_url'       => $siteUrl,
            'ajax_url'       => $ajaxUrl,
            'asset_base'     => $assetBase,
            'nonce'          => $nonce,
            'header_content' => $headerContent,
            'chat_history'   => $chatHistory,
            'client_id'             => $clientId,
            'site_mode'             => $siteMode,
            'shareable_preview_url' => $shareablePreviewUrl,
        ],
    ];
}

// =============================================================================
// AJAX handlers (called from aisitemanager_clientarea when action POST detected)
// =============================================================================

/**
 * Validate the CSRF nonce + dispatch to the correct AJAX action handler.
 * Always exits — never returns.
 */
function aisitemanager_dispatchAjax(int $clientId): void
{
    header('Content-Type: application/json');

    // Verify we have a valid client ID. requirelogin => true means WHMCS has
    // already authenticated the user before this function is called, so a
    // non-zero $clientId from $vars is the only check we need here.
    // We intentionally do NOT check $_SESSION['loggedin'] — that key is not
    // reliably set in all WHMCS versions.
    if (!$clientId) {
        http_response_code(401);
        echo json_encode(['error' => 'You must be logged into your account to use AI Site Manager.']);
        exit;
    }

    // CSRF nonce validation.
    $nonce         = $_POST['nonce'] ?? '';
    $expectedNonce = $_SESSION['aisitemanager_nonce'] ?? '';
    if (empty($nonce) || !hash_equals($expectedNonce, $nonce)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request token. Please reload the page and try again.']);
        exit;
    }

    // Load module config.
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Module config.php not found.']);
        exit;
    }
    $config = require $configFile;

    // Route to the correct action.
    $action = trim($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'chat':
                aisitemanager_ajaxChat($clientId, $config);
                break;
            case 'commit':
                aisitemanager_ajaxCommit($clientId, $config);
                break;
            case 'discard':
                aisitemanager_ajaxDiscard($clientId, $config);
                break;
            case 'set_site_mode':
                aisitemanager_ajaxSetSiteMode($clientId);
                break;
            case 'clearChat':
                aisitemanager_ajaxClearChat($clientId);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => "Unknown action '{$action}'."]);
                exit;
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error: ' . $e->getMessage() . ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
        exit;
    }
}

/**
 * AJAX — handle a chat message from the client.
 */
function aisitemanager_ajaxChat(int $clientId, array $config): void
{
    $message = trim($_POST['message'] ?? '');
    $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

    // Inject current-page context so Claude knows which page the user is looking at.
    // The JS sends the relative path of the page currently shown in the preview iframe.
    $currentPage = trim($_POST['current_page'] ?? '');
    if (!empty($currentPage)) {
        // Sanitize: reject traversal, strip leading slashes, allow only safe path chars.
        $currentPage = ltrim(str_replace('..', '', $currentPage), '/');
        $currentPage = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $currentPage);
    }
    if (!empty($currentPage)) {
        $message = "[Context: The customer is currently viewing '{$currentPage}' in the preview.]\n\n" . $message;
    }

    // A message OR an attached file is required.
    if ($message === '' && !$hasFile) {
        http_response_code(400);
        echo json_encode(['error' => 'Message cannot be empty.']);
        exit;
    }

    // Validate the uploaded file early — fail fast before any FTP connection.
    $uploadMeta = null;
    if ($hasFile) {
        $uploadMeta = aisitemanager_validateUpload($_FILES['attachment']);
        if (isset($uploadMeta['error'])) {
            http_response_code(400);
            echo json_encode(['error' => $uploadMeta['error']]);
            exit;
        }
    }

    $account = aisitemanager_ajaxLoadAccount($clientId);
    $apiKey  = aisitemanager_ajaxGetApiKey();
    if (empty($apiKey)) {
        http_response_code(503);
        echo json_encode(['error' => 'AI Site Manager is not configured yet. Please ask your hosting provider to complete setup.']);
        exit;
    }

    $client  = Capsule::table('tblclients')->where('id', $clientId)->first(['firstname']);
    $hosting = Capsule::table('tblhosting')->where('id', $account->whmcs_service_id)->first(['domain']);

    $customerName   = $client  ? $client->firstname  : 'Customer';
    $customerDomain = $hosting ? $hosting->domain    : '';

    // Keep only the last 40 rows (20 user+assistant pairs) for Claude context.
    // Full history is still in the DB; this just caps token usage per request.
    $historyLimit = max(2, (int)($config['max_context_messages'] ?? 20)) * 2;
    $chatHistory = Capsule::table('mod_aisitemanager_chat_history')
        ->where('whmcs_client_id', $clientId)
        ->orderBy('id', 'desc')
        ->limit($historyLimit)
        ->get(['role', 'message'])
        ->toArray();
    $chatHistory = array_reverse($chatHistory);

    $ftpPassword = \WHMCS\Module\Addon\AiSiteManager\Encryption::decrypt($account->ftp_password);
    $ftp = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
        $account->ftp_host, (int)$account->ftp_port,
        $account->ftp_username, $ftpPassword,
        (int)($config['ftp_timeout'] ?? 30)
    );
    $ftp->connect();

    // Process the uploaded file: upload image to FTP and/or base64-encode for Claude.
    $attachment = null;
    if ($uploadMeta) {
        try {
            $attachment = aisitemanager_processUpload($uploadMeta, $ftp);
        } catch (\Exception $e) {
            // Non-fatal: Claude will still see the text message.
            // Append a note so Claude knows a file upload was attempted.
            $message .= "\n\n[Note: The customer attempted to attach a file ("
                . htmlspecialchars($uploadMeta['original_name'], ENT_QUOTES, 'UTF-8')
                . ") but it could not be processed: " . $e->getMessage() . "]";
        }
    }

    $staging = new \WHMCS\Module\Addon\AiSiteManager\StagingManager($ftp, $config['staging_dir'] ?? '.ai_staging', $clientId);
    $proxy   = new \WHMCS\Module\Addon\AiSiteManager\ClaudeProxy(
        $apiKey, $ftp, $staging,
        (int)($config['max_context_messages'] ?? 20),
        (int)($config['max_read_file_bytes'] ?? 524288)
    );

    $result       = $proxy->chat($message, $chatHistory, $customerName, $customerDomain, $attachment);
    $previewToken = null;
    if ($result['staging_written']) {
        $previewToken = $staging->generatePreviewToken(
            (int)($config['preview_token_ttl'] ?? 28800),
            $customerDomain  // Pass live domain so ai_preview.php injects correct <base> tag.
        );
    }
    $ftp->disconnect();

    // Build the message text to save in history (include file name if one was attached).
    $savedMessage = $message;
    if ($attachment) {
        $savedMessage = ($message !== '' ? $message . "\n" : '') . '[Attached: ' . $attachment['filename'] . ']';
    }

    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_aisitemanager_chat_history')->insert([
        ['whmcs_client_id' => $clientId, 'role' => 'user',      'message' => $savedMessage,       'created_at' => $now],
        ['whmcs_client_id' => $clientId, 'role' => 'assistant', 'message' => $result['response'], 'created_at' => $now],
    ]);

    echo json_encode([
        'response'        => $result['response'],
        'operations'      => $result['operations'],
        'staging_written' => $result['staging_written'],
        'preview_token'   => $previewToken,
    ]);
    exit;
}

/**
 * Validate a file from $_FILES before processing.
 *
 * Returns an array of metadata on success, or ['error' => '...'] on failure.
 *
 * @param  array $fileArr  A single entry from $_FILES (e.g. $_FILES['attachment']).
 * @return array
 */
function aisitemanager_validateUpload(array $fileArr): array
{
    // PHP upload error codes.
    if ($fileArr['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server\'s maximum upload size.',
            UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the maximum allowed size.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: no temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload was blocked by a server extension.',
        ];
        return ['error' => $errorMessages[$fileArr['error']] ?? 'Upload failed (code ' . $fileArr['error'] . ').'];
    }

    // 5 MB hard limit.
    if ($fileArr['size'] > 5 * 1024 * 1024) {
        return ['error' => 'File is too large. Please choose a file under 5 MB.'];
    }

    // Detect the real MIME type — do not trust the browser-reported Content-Type.
    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($fileArr['tmp_name']);

    // Allowed MIME types mapped to their category ('image' or 'text').
    $allowedMimes = [
        'image/jpeg'             => 'image',
        'image/png'              => 'image',
        'image/gif'              => 'image',
        'image/webp'             => 'image',
        'text/html'              => 'text',
        'text/css'               => 'text',
        'application/javascript' => 'text',
        'text/javascript'        => 'text',
        'text/plain'             => 'text',
        'text/xml'               => 'text',
        'application/xml'        => 'text',
    ];

    if (!isset($allowedMimes[$mimeType])) {
        return ['error' => 'File type not supported. You can attach images (JPG, PNG, GIF, WebP) or text files (HTML, CSS, JS, TXT).'];
    }

    return [
        'tmp_path'      => $fileArr['tmp_name'],
        'original_name' => basename($fileArr['name']),
        'size'          => (int)$fileArr['size'],
        'mime_type'     => $mimeType,
        'category'      => $allowedMimes[$mimeType],
    ];
}

/**
 * Process a validated upload.
 *
 * For images: reads binary data, base64-encodes it for Claude vision, and
 *             uploads the raw file to public_html/images/ via FTP so it
 *             becomes a real URL the site can reference.
 *
 * For text files: reads and returns the plain-text content for Claude context.
 *
 * @param  array     $meta  Validated metadata from aisitemanager_validateUpload().
 * @param  \WHMCS\Module\Addon\AiSiteManager\FtpClient $ftp  Connected FTP client.
 * @return array            Attachment descriptor for ClaudeProxy::chat().
 * @throws \RuntimeException if the file cannot be read from the temp path.
 */
function aisitemanager_processUpload(array $meta, \WHMCS\Module\Addon\AiSiteManager\FtpClient $ftp): array
{
    $rawData = file_get_contents($meta['tmp_path']);
    if ($rawData === false) {
        throw new \RuntimeException('Could not read the uploaded file from the server temporary directory.');
    }

    // Sanitize the filename: keep letters, digits, dots, dashes, underscores only.
    $safeName = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $meta['original_name']);
    $safeName = ltrim($safeName, '.');  // No leading dots.

    if ($meta['category'] === 'image') {
        // Upload the image directly to public_html/images/ (not staging) so it
        // gets a real URL the customer can use in their pages immediately.
        $ftpPath = 'images/' . $safeName;
        $ftpNote = '';
        try {
            $ftp->writeFile($ftpPath, $rawData);
        } catch (\Exception $e) {
            // Upload failed — Claude can still see the image via base64 vision,
            // but won't be able to reference it as an existing URL.
            $ftpPath = '';
            $ftpNote = ' (Note: could not upload to server — ' . $e->getMessage() . ')';
        }

        return [
            'type'      => 'image',
            'filename'  => $safeName,
            'ftp_path'  => $ftpPath,   // Empty string if upload failed.
            'ftp_note'  => $ftpNote,
            'mime_type' => $meta['mime_type'],
            'data'      => base64_encode($rawData),
        ];
    }

    // Text file — return content for Claude to read as context.
    return [
        'type'     => 'text',
        'filename' => $safeName,
        'content'  => $rawData,
    ];
}

/**
 * AJAX — commit all staged changes to the live site.
 */
function aisitemanager_ajaxCommit(int $clientId, array $config): void
{
    $account     = aisitemanager_ajaxLoadAccount($clientId);
    $ftpPassword = \WHMCS\Module\Addon\AiSiteManager\Encryption::decrypt($account->ftp_password);

    $ftp = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
        $account->ftp_host, (int)$account->ftp_port,
        $account->ftp_username, $ftpPassword,
        (int)($config['ftp_timeout'] ?? 30)
    );
    $ftp->connect();

    $staging = new \WHMCS\Module\Addon\AiSiteManager\StagingManager($ftp, $config['staging_dir'] ?? '.ai_staging', $clientId);
    $log     = $staging->commit();
    $ftp->disconnect();

    Capsule::table('mod_aisitemanager_chat_history')->insert([
        'whmcs_client_id' => $clientId,
        'role'            => 'assistant',
        'message'         => 'All your changes have been published to your live website. Your visitors can now see the updates!',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    echo json_encode(['message' => 'All staged changes have been published to your live site.', 'log' => $log]);
    exit;
}

/**
 * AJAX — discard all staged changes.
 */
function aisitemanager_ajaxDiscard(int $clientId, array $config): void
{
    $account     = aisitemanager_ajaxLoadAccount($clientId);
    $ftpPassword = \WHMCS\Module\Addon\AiSiteManager\Encryption::decrypt($account->ftp_password);

    $ftp = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
        $account->ftp_host, (int)$account->ftp_port,
        $account->ftp_username, $ftpPassword,
        (int)($config['ftp_timeout'] ?? 30)
    );
    $ftp->connect();

    $staging = new \WHMCS\Module\Addon\AiSiteManager\StagingManager($ftp, $config['staging_dir'] ?? '.ai_staging', $clientId);
    $staging->discard();
    $ftp->disconnect();

    Capsule::table('mod_aisitemanager_chat_history')->insert([
        'whmcs_client_id' => $clientId,
        'role'            => 'assistant',
        'message'         => 'All staged changes have been discarded. Your live website is unchanged.',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    echo json_encode(['message' => 'All staged changes have been discarded.']);
    exit;
}

/**
 * Save site_mode for this client and regenerate the preview token.
 * Returns the new preview URL so JS can reload the iframe immediately.
 */
function aisitemanager_ajaxSetSiteMode(int $clientId): void
{
    $mode = trim($_POST['mode'] ?? '');
    if (!in_array($mode, ['construction', 'production'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid mode.']);
        exit;
    }

    $configFile = __DIR__ . '/config.php';
    $config     = file_exists($configFile) ? require $configFile : [];
    $stagingDir = $config['staging_dir'] ?? '.ai_staging';

    // Save to DB.
    Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_client_id', $clientId)
        ->update(['site_mode' => $mode]);

    // Reload the full account row after update.
    $account = Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_client_id', $clientId)
        ->first();

    // Resolve domain.
    $siteDomain = '';
    $hosting    = Capsule::table('tblhosting')
        ->where('id', $account->whmcs_service_id)
        ->value('domain');
    if ($hosting) {
        $siteDomain = (string)$hosting;
    }

    // Regenerate token with new site_mode so ai_preview.php picks it up.
    $previewToken = null;
    try {
        $ftpPassword = \WHMCS\Module\Addon\AiSiteManager\Encryption::decrypt($account->ftp_password);
        $ftp = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
            $account->ftp_host,
            (int)$account->ftp_port,
            $account->ftp_username,
            $ftpPassword,
            (int)($config['ftp_timeout'] ?? 30)
        );
        $ftp->connect();
        $staging      = new \WHMCS\Module\Addon\AiSiteManager\StagingManager($ftp, $stagingDir, $clientId);
        $previewToken = $staging->generatePreviewToken(
            (int)($config['preview_token_ttl'] ?? 28800),
            $siteDomain,
            $mode
        );
        $ftp->disconnect();
    } catch (\Exception $e) {
        logActivity("AI Site Manager: set_site_mode token regen failed for #{$clientId}: " . $e->getMessage());
    }

    $serverHostname = $account->ftp_host        ?? '';
    $cpanelUser     = $account->cpanel_username ?? '';
    $previewBase    = ($serverHostname && $cpanelUser)
        ? 'https://' . $serverHostname . '/~' . $cpanelUser . '/'
        : '';

    $proxyUrl   = $previewToken
        ? $previewBase . 'ai_preview.php?t=' . urlencode($previewToken)
        : $previewBase;

    $stagingActive = (bool)$account->staging_active;

    // Always proxy through ai_preview.php — the proxy injects CSS fixes that
    // ensure consistent rendering in the iframe (see clientarea load for details).
    $previewUrl = $proxyUrl;

    $shareableUrl = $previewToken
        ? $previewBase . 'ai_preview.php?t=' . urlencode($previewToken)
        : '';

    echo json_encode([
        'mode'          => $mode,
        'preview_url'   => $previewUrl,
        'shareable_url' => $shareableUrl,
        'preview_token' => $previewToken ?? '',
    ]);
    exit;
}

/**
 * AJAX — clear all chat history for this client.
 */
function aisitemanager_ajaxClearChat(int $clientId): void
{
    Capsule::table('mod_aisitemanager_chat_history')
        ->where('whmcs_client_id', $clientId)
        ->delete();

    echo json_encode(['ok' => true]);
    exit;
}

/**
 * Load and verify the AI account for $clientId (must be enabled).
 */
function aisitemanager_ajaxLoadAccount(int $clientId): object
{
    $account = Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_client_id', $clientId)
        ->where('ai_enabled', 1)
        ->first();

    if (!$account) {
        http_response_code(403);
        echo json_encode(['error' => 'AI Site Manager is not enabled for your account.']);
        exit;
    }

    return $account;
}

/**
 * Retrieve the Anthropic API key from the settings table.
 */
function aisitemanager_ajaxGetApiKey(): string
{
    $row = Capsule::table('mod_aisitemanager_settings')
        ->where('setting_key', 'api_key')
        ->first(['setting_value']);

    return $row ? trim($row->setting_value) : '';
}
