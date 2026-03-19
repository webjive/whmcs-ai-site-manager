<?php
/**
 * AI Site Manager — WHMCS Hooks
 * WebJIVE · https://web-jive.com
 *
 * This file is auto-detected by WHMCS when it exists in the addon module root.
 * Hooks registered here fire at the appropriate WHMCS lifecycle events.
 *
 * Hook registered:
 *   AfterModuleCreate — Automatically provisions AI Site Manager when a new
 *                        hosting account is successfully created in WHMCS.
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

use WHMCS\Database\Capsule;

// Autoloader for lib/ classes — must be registered here because hooks.php is
// loaded by WHMCS independently of aisitemanager.php, and the AfterModuleCreate
// hook needs FtpClient and Encryption before the main module file is loaded.
spl_autoload_register(function (string $class) {
    $prefix = 'WHMCS\\Module\\Addon\\AiSiteManager\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file          = __DIR__ . '/lib/' . $relativeClass . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// =============================================================================
// AfterModuleCreate
// Fires after WHMCS successfully creates a cPanel hosting account.
// =============================================================================
add_hook('AfterModuleCreate', 1, function (array $vars) {
    // Only proceed if this is a cPanel hosting module.
    $serverType = $vars['params']['moduletype'] ?? '';
    if (strtolower($serverType) !== 'cpanel') {
        return;
    }

    $serviceId = (int)($vars['params']['serviceid'] ?? 0);
    $clientId  = (int)($vars['params']['clientsdetails']['userid'] ?? 0);

    if (!$serviceId || !$clientId) {
        return;
    }

    // Load the addon config (encryption key, ports, etc.)
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        // Config not set up yet — skip auto-provisioning. Admin can provision manually.
        logActivity("AI Site Manager: Skipping auto-provision for service #{$serviceId} — config.php not found.");
        return;
    }
    $config = require $configFile;

    // Check that the addon settings contain an API key before provisioning.
    $apiKeyRow = Capsule::table('mod_aisitemanager_settings')
        ->where('setting_key', 'api_key')
        ->first(['setting_value']);

    if (!$apiKeyRow || empty(trim($apiKeyRow->setting_value))) {
        logActivity(
            "AI Site Manager: Skipping auto-provision for service #{$serviceId} — " .
            "Anthropic API key not configured in admin settings."
        );
        return;
    }

    // Check the linked products list. Auto-provisioning only fires for products
    // that have been explicitly linked to AI Site Manager in the admin settings.
    // If the list is empty, auto-provisioning is disabled (admin must provision manually).
    $linkedRow = Capsule::table('mod_aisitemanager_settings')
        ->where('setting_key', 'linked_products')
        ->first(['setting_value']);

    $linkedProductIds = $linkedRow ? (json_decode($linkedRow->setting_value, true) ?: []) : [];

    if (empty($linkedProductIds)) {
        // No products linked — auto-provisioning is disabled for all products.
        return;
    }

    // Look up the product ID (tblhosting.packageid) for this service.
    $serviceRow = Capsule::table('tblhosting')->where('id', $serviceId)->first(['packageid']);
    $productId  = (int)($serviceRow->packageid ?? 0);

    if (!$productId || !in_array($productId, $linkedProductIds, true)) {
        // This product is not in the linked list — skip auto-provisioning.
        return;
    }

    // Check the account isn't already provisioned.
    $existing = Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_service_id', $serviceId)
        ->first(['id']);

    if ($existing) {
        return; // Already provisioned — nothing to do.
    }

    // Run provisioning.
    try {
        aisitemanager_provisionAccount($serviceId, $clientId, $config);
        logActivity("AI Site Manager: Auto-provisioned service #{$serviceId} (product #{$productId}) for client #{$clientId}.");
    } catch (\Exception $e) {
        logActivity(
            "AI Site Manager: Auto-provisioning FAILED for service #{$serviceId}: " . $e->getMessage()
        );
    }
});

// =============================================================================
// ClientAreaFooterOutput — AI Editor button + Homepage Panel
//
// Two injections via a single script tag:
//
//   1. injectButton() — adds an "AI Editor" button next to "Email MX Changer" /
//      "Visit Website" on the hosting service details page, only for services
//      linked to this addon.
//
//   2. injectPanel() — on the client area homepage, replaces the Sitejet Builder
//      card (#sitejetPromoPanel) with an "AI Site Editor" panel showing a live
//      website thumbnail (thum.io), a domain selector, and a "Start Editing"
//      button that links to the AI Site Manager module.
//
// PHP passes two JSON arrays: service IDs (for button targeting) and domain
// names (for the homepage panel dropdown + thumbnail preview).
// =============================================================================
add_hook('ClientAreaFooterOutput', 1, function (array $vars) {
    try {
        // Require an authenticated client session.
        $clientId = (int)(
            $vars['clientsdetails']['userid']
            ?? $_SESSION['uid']
            ?? 0
        );
        if (!$clientId) {
            return '';
        }

        // Fetch all AI Site Manager accounts for this client, including the
        // domain name from tblhosting so we can build the preview panel.
        $serviceIds = [];
        $domains    = [];
        foreach (
            Capsule::table('mod_aisitemanager_accounts as a')
                ->join('tblhosting as h', 'a.whmcs_service_id', '=', 'h.id')
                ->where('a.whmcs_client_id', $clientId)
                ->select(['h.id as service_id', 'h.domain'])
                ->get() as $row
        ) {
            $serviceIds[] = (int)    $row->service_id;
            $domains[]    = (string) $row->domain;
        }

        if (empty($serviceIds)) {
            return '';
        }

        $serviceIdsJs = json_encode($serviceIds);
        $domainsJs    = json_encode($domains);

        return <<<JS
<script>
(function () {
    var aiSvcIds  = {$serviceIdsJs};
    var aiDomains = {$domainsJs};

    /* -------------------------------------------------------------------
       Detect the service ID from the current page URL.
       Supports:
         - /clientarea/services/912  (SEO-friendly)
         - clientarea.php?action=productdetails&id=912  (classic)
         - index.php?rp=/clientarea/services/912  (WHMCS rewrite target)
    ------------------------------------------------------------------- */
    function getSvcId() {
        var path  = window.location.pathname;
        var query = window.location.search;

        var m = path.match(/\/services\/(\d+)/);
        if (m) return +m[1];

        var p = new URLSearchParams(query);

        if (p.get('action') === 'productdetails' && p.get('id')) {
            return +p.get('id');
        }

        var rp = p.get('rp') || '';
        var rm = rp.match(/\/services\/(\d+)/);
        if (rm) return +rm[1];

        return null;
    }

    /* -------------------------------------------------------------------
       1. Inject "AI Editor" button on service detail pages.
    ------------------------------------------------------------------- */
    function injectButton() {
        if (document.getElementById('aisitemgr-btn')) return;

        var sid = getSvcId();
        if (!sid || aiSvcIds.indexOf(sid) === -1) return;

        // a.btn — literal class match avoids cPanel's lu-tile--btn tiles.
        var anchors = document.querySelectorAll('a.btn');
        var ref = null;
        for (var i = 0; i < anchors.length; i++) {
            var t    = anchors[i].textContent.trim();
            var href = anchors[i].getAttribute('href') || '';
            if (href.indexOf('mxchanger') !== -1 || t === 'Email MX Changer' || t === 'Visit Website') {
                ref = anchors[i];
            }
        }
        if (!ref) return;

        var btn          = document.createElement('a');
        btn.id           = 'aisitemgr-btn';
        btn.href         = 'index.php?m=aisitemanager';
        btn.className    = ref.className;
        btn.style.marginLeft = '5px';
        btn.innerHTML    = '<i class="fa fa-magic"></i> AI Editor';
        ref.parentNode.insertBefore(btn, ref.nextSibling);
    }

    /* -------------------------------------------------------------------
       2. Replace the Sitejet Builder homepage panel with AI Site Editor.
          - Retitles the card header (icon + "AI Site Editor").
          - Replaces the empty card-footer with a card-body containing:
              • thum.io live screenshot thumbnail
              • Domain selector <select>
              • "Start Editing" button → index.php?m=aisitemanager
    ------------------------------------------------------------------- */
    function injectPanel() {
        var panel = document.getElementById('sitejetPromoPanel');
        if (!panel || panel.getAttribute('data-ai-injected')) return;
        if (!aiDomains.length) return;

        panel.setAttribute('data-ai-injected', '1');

        // Retitle the card header.
        var titleEl = panel.querySelector('.card-title');
        if (titleEl) {
            titleEl.innerHTML = '<i class="fas fa-magic"></i>&nbsp;AI Site Editor';
        }
        panel.setAttribute('menuitemname', 'AI Site Editor');

        // Remove the old (empty) card-footer.
        var oldFooter = panel.querySelector('.card-footer');
        if (oldFooter) { oldFooter.parentNode.removeChild(oldFooter); }

        var thumbBase   = 'https://image.thum.io/get/width/300/crop/400/';
        var firstDomain = aiDomains[0];

        // Build card-body.
        var body = document.createElement('div');
        body.className   = 'card-body';
        body.style.padding = '15px';

        // Preview thumbnail.
        var img = document.createElement('img');
        img.id            = 'ai-site-preview-img';
        img.src           = thumbBase + firstDomain;
        img.alt           = 'Website preview';
        img.style.cssText = 'width:100%;max-width:300px;border:1px solid #ddd;border-radius:4px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;';
        img.onerror       = function () { this.style.display = 'none'; };
        body.appendChild(img);

        // Bottom row: domain selector (45%) + Start Editing button (45%) side by side.
        var bottomRow = document.createElement('div');
        bottomRow.style.cssText = 'display:flex;align-items:center;gap:10%;';

        // Domain selector — 45% wide.
        var select = document.createElement('select');
        select.id            = 'ai-domain-select';
        select.className     = 'form-control';
        select.style.cssText = 'width:45%;flex:0 0 45%;';
        for (var i = 0; i < aiDomains.length; i++) {
            var opt         = document.createElement('option');
            opt.value       = aiDomains[i];
            opt.textContent = aiDomains[i];
            select.appendChild(opt);
        }
        bottomRow.appendChild(select);

        // Update thumbnail when domain selection changes.
        select.addEventListener('change', function () {
            img.style.display = 'block';
            img.src = thumbBase + this.value;
        });

        // "Start Editing" button — 45% wide.
        var link = document.createElement('a');
        link.href            = 'index.php?m=aisitemanager';
        link.className       = 'btn btn-primary';
        link.style.cssText   = 'width:45%;flex:0 0 45%;text-align:center;';
        link.innerHTML       = '<i class="fa fa-magic"></i>&nbsp;Start Editing';
        bottomRow.appendChild(link);

        body.appendChild(bottomRow);

        panel.appendChild(body);
    }

    function run() {
        injectButton();
        injectPanel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
    setTimeout(run, 600);
}());
</script>
JS;
    } catch (\Exception $e) {
        return '';
    }
});

// =============================================================================
// AfterModuleTerminate
// Fires after WHMCS terminates a cPanel hosting account.
// Deprovision the AI Site Manager entry for the account.
// =============================================================================
add_hook('AfterModuleTerminate', 1, function (array $vars) {
    $serverType = $vars['params']['moduletype'] ?? '';
    if (strtolower($serverType) !== 'cpanel') {
        return;
    }

    $serviceId = (int)($vars['params']['serviceid'] ?? 0);
    if (!$serviceId) {
        return;
    }

    $account = Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_service_id', $serviceId)
        ->first();

    if (!$account) {
        return;
    }

    // Remove the account record and its chat history.
    Capsule::table('mod_aisitemanager_chat_history')
        ->where('whmcs_client_id', $account->whmcs_client_id)
        ->delete();

    Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_service_id', $serviceId)
        ->delete();

    logActivity(
        "AI Site Manager: Removed account entry for terminated service #{$serviceId}."
    );
});

// =============================================================================
// Shared provisioning function
// Called by both the hook above and the admin panel "Provision" button.
// =============================================================================

/**
 * Provision an AI Site Manager FTP sub-account for a WHMCS hosting service.
 *
 * Steps:
 *   1. Load the hosting service and server records from WHMCS DB.
 *   2. Call the cPanel FTP API (via WHM passthrough) to create a sub-account
 *      scoped to public_html.
 *   3. Connect via FTPS with the new credentials.
 *   4. Deploy ai_preview.php to public_html/.
 *   5. Create .ai_staging/ with its protective .htaccess.
 *   6. Append Disallow: /.ai_staging/ to public_html/robots.txt.
 *   7. Store the encrypted credentials in mod_aisitemanager_accounts.
 *
 * @param  int   $serviceId WHMCS tblhosting.id.
 * @param  int   $clientId  WHMCS tblclients.id.
 * @param  array $config    Module config array from config.php.
 * @throws \RuntimeException on any failure.
 */
function aisitemanager_provisionAccount(int $serviceId, int $clientId, array $config): void
{
    // ------------------------------------------------------------------
    // 1. Load hosting service and server info
    // ------------------------------------------------------------------
    $hosting = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->first();

    if (!$hosting) {
        throw new \RuntimeException("Service #{$serviceId} not found in tblhosting.");
    }

    $server = Capsule::table('tblservers')
        ->where('id', $hosting->server)
        ->first();

    if (!$server) {
        throw new \RuntimeException("Server #{$hosting->server} not found in tblservers.");
    }

    $cpanelUsername = $hosting->username;
    $domain         = $hosting->domain;
    $ftpHost        = $server->hostname ?: $server->ipaddress;
    $ftpPort        = (int)($config['default_ftp_port'] ?? 21);
    $whmHost        = $server->hostname ?: $server->ipaddress;
    $whmPort        = $server->port ?: 2087;
    $whmUser        = $server->username;
    $whmHash        = $server->accesshash;

    // ------------------------------------------------------------------
    // 2. Generate FTP sub-account credentials
    // ------------------------------------------------------------------
    // Sub-account name: "aisitemgr" (cPanel appends @domain automatically).
    $ftpSubUser  = 'aisitemgr';
    $ftpPassword = aisitemanager_generatePassword(24);

    // Call cPanel UAPI directly on port 2083, authenticated via a WHM-issued
    // cPanel session token.
    //
    // WHY SESSION TOKEN INSTEAD OF BASIC AUTH:
    //   We cannot decrypt tblhosting.password in hook/admin scope because
    //   decrypt_db_data() may not be available outside a full WHMCS bootstrap.
    //   Instead we ask WHM — which we already have hash credentials for — to
    //   create a short-lived cPanel session. The resulting cpsessXXXX token is
    //   embedded in the UAPI URL path and acts as authentication; no password
    //   decryption is required.
    //
    // WHY NOT WHM PROXY (/json-api/cpanel):
    //   WHM's proxy layer drops the `password` parameter when it internally
    //   re-routes API 2 Ftp::addftp to UAPI Ftp::add_ftp, regardless of GET or
    //   POST. The session-token approach calls UAPI directly and bypasses the proxy.
    $uapiSslVerify = (bool)($config['whm_ssl_verify'] ?? false);

    // Ask WHM to create a cPanel session for this account.
    $sessionResult = aisitemanager_callWhmApi(
        $whmHost, $whmPort, $whmUser, $whmHash, $uapiSslVerify,
        'create_user_session',
        ['api.version' => 1, 'user' => $cpanelUsername, 'service' => 'cpaneld']
    );
    // Response key is 'cp_security_token', value looks like '/cpsess9692670382'.
    // Strip the leading slash so it embeds cleanly in the URL path.
    // The 'url' field is the activation URL — curl must visit it first to
    // receive the cpsession cookie that unlocks subsequent UAPI calls.
    $sessionToken  = ltrim($sessionResult['data']['cp_security_token'] ?? '', '/');
    $activationUrl = $sessionResult['data']['url'] ?? '';
    if (empty($sessionToken) || empty($activationUrl)) {
        throw new \RuntimeException(
            'Could not create cPanel session via WHM: ' . json_encode($sessionResult)
        );
    }

    // Activate the session ONCE and share the cookie file across all UAPI
    // calls. Each call to aisitemanager_callCpanelUapi reuses the same file
    // so the session cookie is never lost between the delete and create steps.
    $cookieFile = aisitemanager_activateCpanelSession($activationUrl, $uapiSslVerify);

    try {
        // Delete the sub-account first if it already exists (e.g. from a
        // previous failed provisioning attempt). destroy=0 keeps the files.
        // Status 0 (not found) is silently ignored.
        aisitemanager_callCpanelUapi(
            $ftpHost, $sessionToken, $cookieFile, $uapiSslVerify,
            'Ftp/delete_ftp',
            ['user' => "{$ftpSubUser}@{$domain}", 'destroy' => 0]
        );

        $apiResult = aisitemanager_callCpanelUapi(
            $ftpHost, $sessionToken, $cookieFile, $uapiSslVerify,
            'Ftp/add_ftp',
            [
                // cPanel Ftp::add_ftp uses 'user' and 'pass' — confirmed from
                // /usr/local/cpanel/Cpanel/API/Ftp.pm (docs say login/password).
                'user'    => $ftpSubUser,
                'pass'    => $ftpPassword,
                'homedir' => 'public_html',
                'quota'   => 0,
            ]
        );

        // Always log the full raw response so admin can diagnose failures.
        logActivity('AI Site Manager: cPanel add_ftp UAPI response: ' . json_encode($apiResult));

        // Direct UAPI response format: {"status": 1, "errors": null, ...}
        $apiSuccess = isset($apiResult['status']) && $apiResult['status'] == 1;

        if (!$apiSuccess) {
            $errors = $apiResult['errors'] ?? null;
            $msg = (is_array($errors) && count($errors))
                ? implode('; ', $errors)
                : json_encode($apiResult);
            throw new \RuntimeException("cPanel FTP UAPI error: {$msg}");
        }
    } finally {
        @unlink($cookieFile); // Always clean up, even on exception.
    }

    // Full FTP login is subuser@domain (cPanel convention).
    $ftpUsername = "{$ftpSubUser}@{$domain}";

    // ------------------------------------------------------------------
    // 3. Connect via FTPS using new credentials
    // ------------------------------------------------------------------
    $ftp = new \WHMCS\Module\Addon\AiSiteManager\FtpClient(
        $ftpHost,
        $ftpPort,
        $ftpUsername,
        $ftpPassword,
        $config['ftp_timeout'] ?? 30
    );
    $ftp->connect();

    // ------------------------------------------------------------------
    // 4. Deploy ai_preview.php to public_html/
    // ------------------------------------------------------------------
    $shimTemplate = file_get_contents(__DIR__ . '/deploy/ai_preview.php');
    if ($shimTemplate === false) {
        throw new \RuntimeException('Could not read deploy/ai_preview.php template.');
    }
    $ftp->writeFile('/ai_preview.php', $shimTemplate);

    // ------------------------------------------------------------------
    // 5. Create .ai_staging/ with protective .htaccess
    // ------------------------------------------------------------------
    $stagingDir = $config['staging_dir'] ?? '.ai_staging';
    if (!$ftp->directoryExists("/{$stagingDir}")) {
        $ftp->createDirectory("/{$stagingDir}");
    }
    $ftp->writeFile(
        "/{$stagingDir}/.htaccess",
        "Order Deny,Allow\nDeny from all\n"
    );

    // ------------------------------------------------------------------
    // 6. Update robots.txt — append staging disallow
    // ------------------------------------------------------------------
    $robotsDisallow = "\nUser-agent: *\nDisallow: /{$stagingDir}/\n";
    if ($ftp->fileExists('/robots.txt')) {
        $robotsContent = $ftp->readFile('/robots.txt');
        if (strpos($robotsContent, "/{$stagingDir}/") === false) {
            $ftp->writeFile('/robots.txt', $robotsContent . $robotsDisallow);
        }
    } else {
        $ftp->writeFile('/robots.txt', "User-agent: *\nDisallow: /{$stagingDir}/\n");
    }

    $ftp->disconnect();

    // ------------------------------------------------------------------
    // 7. Store encrypted credentials in the database
    // ------------------------------------------------------------------
    Capsule::table('mod_aisitemanager_accounts')->insert([
        'whmcs_client_id' => $clientId,
        'whmcs_service_id'=> $serviceId,
        'cpanel_username' => $cpanelUsername,
        'ftp_username'    => $ftpUsername,
        'ftp_password'    => \WHMCS\Module\Addon\AiSiteManager\Encryption::encrypt($ftpPassword),
        'ftp_host'        => $ftpHost,
        'ftp_port'        => $ftpPort,
        'ai_enabled'      => 1,
        'staging_active'  => 0,
        'created_at'      => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Deprovision an AI Site Manager account.
 *
 * Removes the DB record. Does NOT delete the FTP sub-account on the cPanel
 * server (admin can do that manually if needed to avoid errors on missing accounts).
 *
 * @param  int $clientId WHMCS client ID.
 */
function aisitemanager_deprovisionAccount(int $clientId): void
{
    Capsule::table('mod_aisitemanager_accounts')
        ->where('whmcs_client_id', $clientId)
        ->delete();
}

// =============================================================================
// WHM API helper
// =============================================================================

/**
 * Call the WHM JSON API.
 *
 * @param  string $host      WHM server hostname or IP.
 * @param  int    $port      WHM port (2087 for SSL).
 * @param  string $user      WHM username (usually 'root' or reseller).
 * @param  string $hash      WHM access hash (from tblservers.accesshash).
 * @param  bool   $sslVerify Whether to verify the WHM SSL certificate.
 * @param  string $endpoint  API endpoint (e.g. 'cpanel' or 'createacct').
 * @param  array  $params    Query parameters.
 * @return array             Decoded JSON response.
 * @throws \RuntimeException on cURL or JSON failure.
 */
function aisitemanager_callWhmApi(
    string $host,
    int    $port,
    string $user,
    string $hash,
    bool   $sslVerify,
    string $endpoint,
    array  $params = [],
    string $method = 'GET'
): array {
    $protocol = 'https';
    $url      = "{$protocol}://{$host}:{$port}/json-api/{$endpoint}";

    // For GET requests append params to the URL; for POST send in the body.
    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    // WHM auth: strip newlines from the accesshash.
    $cleanHash = preg_replace('/\s+/', '', $hash);

    $ch = curl_init($url);

    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: WHM {$user}:{$cleanHash}",
        ],
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        CURLOPT_TIMEOUT        => 30,
    ];

    if ($method === 'POST') {
        $curlOpts[CURLOPT_POST]       = true;
        $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    curl_setopt_array($ch, $curlOpts);

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        throw new \RuntimeException("WHM API cURL error: {$error}");
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('WHM API returned non-JSON response: ' . substr($body, 0, 300));
    }

    return $decoded;
}

/**
 * Activate a WHM-issued cPanel session and return the path to a populated
 * cookie jar file that can be reused for multiple UAPI calls.
 *
 * The caller is responsible for deleting the file when done:
 *   $cookieFile = aisitemanager_activateCpanelSession($url, $ssl);
 *   try { ... aisitemanager_callCpanelUapi(..., $cookieFile, ...); ... }
 *   finally { @unlink($cookieFile); }
 *
 * @param  string $activationUrl  Full login URL from WHM create_user_session response.
 * @param  bool   $sslVerify      Whether to verify the SSL certificate.
 * @return string                 Path to the temp cookie jar file.
 * @throws \RuntimeException      If curl fails to reach the activation URL.
 */
function aisitemanager_activateCpanelSession(string $activationUrl, bool $sslVerify): string
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'aisitemgr_');
    $k          = $sslVerify ? '' : ' -k';

    $cmd = 'curl -s -L' . $k
        . ' --cookie-jar ' . escapeshellarg($cookieFile)
        . ' ' . escapeshellarg($activationUrl)
        . ' > /dev/null 2>&1';

    exec($cmd, $ignored, $rc);

    if ($rc !== 0) {
        @unlink($cookieFile);
        throw new \RuntimeException(
            "cPanel session activation failed (curl exit {$rc})"
        );
    }

    return $cookieFile;
}

/**
 * Call the cPanel UAPI directly on port 2083 using a pre-activated session
 * cookie file.  Uses the system curl binary (not PHP libcurl).
 *
 * WHY SYSTEM CURL:
 *   PHP's libcurl (and some WAF/mod_security rules) can silently strip
 *   parameters — particularly ones named "pass" / "password" — before the
 *   request reaches cPanel's UAPI handler. The system curl binary bypasses
 *   that layer entirely.
 *
 * WHY A SHARED COOKIE FILE:
 *   Session activation (visiting the login URL) must happen exactly once per
 *   session.  Calling activate inside this function and deleting the cookie
 *   file afterwards (as earlier iterations did) broke the second API call
 *   because the session was gone.  The caller activates once and passes the
 *   same file for every subsequent UAPI call.
 *
 * @param  string $host          cPanel server hostname or IP.
 * @param  string $sessionToken  cpsessXXXXXXXXXX token (no leading slash).
 * @param  string $cookieFile    Path to cookie jar created by aisitemanager_activateCpanelSession().
 * @param  bool   $sslVerify     Whether to verify the SSL certificate.
 * @param  string $apiPath       UAPI module/function path (e.g. 'Ftp/add_ftp').
 * @param  array  $params        Query parameters.
 * @return array                 Decoded JSON response.
 * @throws \RuntimeException     On curl failure or non-JSON response.
 */
function aisitemanager_callCpanelUapi(
    string $host,
    string $sessionToken,
    string $cookieFile,
    bool   $sslVerify,
    string $apiPath,
    array  $params = []
): array {
    $k   = $sslVerify ? '' : ' -k';
    $url = 'https://' . $host . ':2083/' . $sessionToken
         . '/execute/' . $apiPath
         . '?' . http_build_query($params);

    $lines = [];
    $cmd   = 'curl -s' . $k
        . ' --cookie ' . escapeshellarg($cookieFile)
        . ' ' . escapeshellarg($url)
        . ' 2>/dev/null';

    exec($cmd, $lines, $rc);

    if ($rc !== 0) {
        throw new \RuntimeException(
            "cPanel UAPI curl request failed (exit {$rc})"
        );
    }

    $body    = implode("\n", $lines);
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new \RuntimeException(
            'cPanel UAPI returned non-JSON: ' . substr($body, 0, 300)
        );
    }

    return $decoded;
}

/**
 * Generate a cryptographically random password.
 *
 * @param  int    $length Desired password length.
 * @return string         Random password string (alphanumeric + symbols).
 */
function aisitemanager_generatePassword(int $length = 24): string
{
    // Alphanumeric only — no special characters.
    // Special chars like & # % + break cPanel's internal FTP password routing
    // even inside a POST body, because WHM re-parses the forwarded params as a
    // query string when proxying API 2 calls to UAPI internally.
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $max   = strlen($chars) - 1;
    $pass  = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, $max)];
    }
    return $pass;
}
