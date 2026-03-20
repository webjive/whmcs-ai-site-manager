<?php
/**
 * AI Site Manager — Staging Preview Shim
 * WebJIVE · https://web-jive.com
 *
 * THIS FILE IS DEPLOYED TO CUSTOMER'S public_html/ ON PROVISIONING.
 * It should NOT be committed inside the WHMCS module directory.
 * The module reads this file and uploads it via FTP during account provisioning.
 *
 * Purpose:
 *   Serves a preview of the customer's website with staged changes applied,
 *   before those changes are committed to the live site. Used by the staging
 *   preview iframe inside the WHMCS AI Site Manager client area.
 *
 * Security:
 *   - Requires a valid, non-expired preview token passed as ?t=TOKEN.
 *   - Token is validated against .ai_staging/.preview_token (written by StagingManager).
 *   - Returns HTTP 403 if the token is missing, invalid, or expired.
 *   - This file is NOT a catch-all router — it serves a specific file per request.
 *
 * URL format:
 *   http://customerdomain.com/ai_preview.php?t=TOKEN[&path=relative/path.html]
 *
 *   If no `path` is given, defaults to index.html or index.php (whichever exists).
 *
 * How it serves content:
 *   1. Validates the preview token.
 *   2. Resolves the requested path within public_html.
 *   3. Checks if a staged version exists in .ai_staging/.
 *   4. Serves staged version if found, live version otherwise.
 *   5. For HTML files, injects a <base> tag so relative links resolve correctly.
 *
 * Limitations:
 *   - PHP files are read as source text, not executed. This means WordPress and
 *     other PHP-based sites will show PHP source in the preview, not rendered HTML.
 *     This is acceptable for the target use case (static/HTML/CSS edits).
 *   - CSS, JS, images are served from the live domain by the browser via the
 *     <base> tag injection, not through this shim.
 */

// ---------------------------------------------------------------------------
// Security: validate preview token
// ---------------------------------------------------------------------------

$tokenParam = isset($_GET['t']) ? trim($_GET['t']) : '';

if (empty($tokenParam) || !preg_match('/^[a-f0-9]{64}$/', $tokenParam)) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Invalid or missing preview token.</p>';
    exit;
}

// The token file is at .ai_staging/.preview_token (written by StagingManager::generatePreviewToken()).
$publicHtmlDir = __DIR__;                             // This file lives in public_html root.
$stagingDir    = $publicHtmlDir . '/.ai_staging';
$tokenFilePath = $stagingDir . '/.preview_token';

if (!file_exists($tokenFilePath)) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>No active staging session.</p>';
    exit;
}

$tokenData = json_decode(file_get_contents($tokenFilePath), true);
if (!is_array($tokenData) || empty($tokenData['token']) || empty($tokenData['expiry'])) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Malformed preview token file.</p>';
    exit;
}

// Constant-time comparison to prevent timing attacks.
if (!hash_equals($tokenData['token'], $tokenParam)) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Invalid preview token.</p>';
    exit;
}

if ((int)$tokenData['expiry'] < time()) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Preview token has expired. Reload the AI Site Manager to generate a new one.</p>';
    exit;
}

// ---------------------------------------------------------------------------
// Resolve the requested file path
// ---------------------------------------------------------------------------

// `path` parameter is a relative path from public_html (e.g., "index.html" or "about/index.html").
// Sanitize it to prevent traversal.
$requestedPath = isset($_GET['path']) ? ltrim($_GET['path'], '/') : '';

// Strip any '..' components.
if (strpos($requestedPath, '..') !== false) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1><p>Invalid path.</p>';
    exit;
}

// Default to index.html or index.php if no path given.
if (empty($requestedPath)) {
    if (file_exists($publicHtmlDir . '/index.html')) {
        $requestedPath = 'index.html';
    } elseif (file_exists($publicHtmlDir . '/index.php')) {
        $requestedPath = 'index.php';
    } else {
        // No index file found — list what's available.
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h2>AI Site Manager Preview</h2>';
        echo '<p>No index file found in the website root. The AI can create one for you.</p>';
        exit;
    }
}

// Resolve the final path, confirming it stays inside public_html.
$liveFilePath   = realpath($publicHtmlDir . '/' . $requestedPath);
$stagedFilePath = $stagingDir . '/' . $requestedPath;

// Verify the resolved live path is inside public_html (defense in depth).
if ($liveFilePath !== false && strpos($liveFilePath, realpath($publicHtmlDir)) !== 0) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Path escapes the website root.</p>';
    exit;
}

// ---------------------------------------------------------------------------
// Determine which version to serve (staged or live)
// ---------------------------------------------------------------------------

// Staged version takes priority if it exists and is not a deletion marker.
$filePath    = null;
$isStaged    = false;
$deletionMarker = '__AISITEMANAGER_DELETE__';

if (file_exists($stagedFilePath) && !is_dir($stagedFilePath)) {
    $stagedContent = file_get_contents($stagedFilePath);
    if ($stagedContent === $deletionMarker) {
        // File is marked for deletion — serve a notice instead.
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p style="background:#fff3cd;padding:12px;font-family:sans-serif;">'
           . '⚠️ This file is marked for deletion. It will be removed from the live site when you click Commit.</p>';
        exit;
    }
    $filePath = $stagedFilePath;
    $isStaged = true;
} elseif ($liveFilePath !== false && file_exists($liveFilePath) && !is_dir($liveFilePath)) {
    $filePath = $liveFilePath;
} else {
    http_response_code(404);
    echo '<h1>404 Not Found</h1><p>File not found: ' . htmlspecialchars($requestedPath) . '</p>';
    exit;
}

// ---------------------------------------------------------------------------
// Determine MIME type and serve the file
// ---------------------------------------------------------------------------

$ext = strtolower(pathinfo($requestedPath, PATHINFO_EXTENSION));

$mimeTypes = [
    'html' => 'text/html',
    'htm'  => 'text/html',
    'php'  => 'text/html',   // PHP source shown as HTML; not executed.
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'json' => 'application/json',
    'xml'  => 'application/xml',
    'txt'  => 'text/plain',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'svg'  => 'image/svg+xml',
    'ico'  => 'image/x-icon',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'pdf'  => 'application/pdf',
];

$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

// ---------------------------------------------------------------------------
// For HTML files: inject <base> tag and a staging notice banner
// ---------------------------------------------------------------------------

$isHtml = in_array($ext, ['html', 'htm', 'php'], true);

if ($isHtml) {
    $content = file_get_contents($filePath);

    // Build the base URL so relative asset paths (CSS, JS, images) resolve correctly.
    //
    // IMPORTANT: We always use the live site's domain (e.g. https://giraffetree.com/)
    // as the base URL, NOT the server tilde URL (https://earth1.webjive.net/~giraffe/).
    // Using the tilde URL would cause every relative asset (CSS, JS, images) to 404
    // because those files are served by the web server under the domain name, not the
    // tilde path. The live domain is stored in the .preview_token file by
    // StagingManager::generatePreviewToken() so this file is completely dynamic —
    // it works for any client's domain with zero hardcoding.
    $liveDomain = !empty($tokenData['site_domain']) ? trim($tokenData['site_domain']) : '';

    if ($liveDomain) {
        // Use stored live domain — assets, JS, CSS all resolve correctly.
        $baseUrl  = 'https://' . $liveDomain . '/';
        $basePath = '/';   // Path component for the nav intercept script below.
    } else {
        // Fallback: derive from server variables (legacy token files without site_domain).
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? '';
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/ai_preview.php'), '/') . '/';
        $baseUrl  = $scheme . '://' . $host . $basePath;
    }

    // The staging notice banner, injected at the top of <body>.
    $stagingBanner = $isStaged
        ? '<div style="position:fixed;bottom:0;left:0;right:0;background:#1a73e8;color:#fff;'
          . 'font-family:sans-serif;font-size:13px;padding:8px 16px;z-index:99999;'
          . 'display:flex;align-items:center;justify-content:center;gap:10px;">'
          . '🔍 <strong>Preview mode</strong> — changes are staged, not live yet. '
          . 'Click <strong>Commit</strong> in AI Site Manager to publish.</div>'
        : '';

    // Inject <base> into <head> so relative links and resources load from live domain.
    // This allows the browser to fetch CSS, images, JS from the actual live site.
    if (stripos($content, '<head') !== false) {
        $content = preg_replace(
            '/(<head[^>]*>)/i',
            '$1' . "\n" . '<base href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '">',
            $content,
            1
        );
    }

    // Navigation tracking script.
    // Intercepts link clicks for BOTH the preview host (earth1.webjive.net) AND the
    // live domain (e.g. giraffetree.com). This is critical when <base> points to the
    // live domain — absolute links in the HTML resolve to the live domain, so without
    // intercepting them the iframe would navigate away to the live site and lose the
    // staging context entirely.
    $navScript = '<script>'
        . '(function(){'
        .   'var __tok=' . json_encode($tokenParam) . ';'
        // The live site's hostname (e.g. "giraffetree.com"). Links to this host are
        // intercepted and re-routed through ai_preview.php just like same-host links.
        .   'var __live=' . json_encode($liveDomain) . ';'
        .   'document.addEventListener("click",function(e){'
        .     'var a=e.target.closest("a");'
        .     'if(!a||!a.href)return;'
        .     'var url;try{url=new URL(a.href);}catch(_){return;}'
        //  Only intercept links to the preview host OR the live domain.
        //  Truly external links (third-party domains) are left alone.
        .     'var isLocal=url.host===location.host;'
        .     'var isLive=__live&&url.host===__live;'
        .     'if(!isLocal&&!isLive)return;'
        //  Skip hash-only same-page anchors.
        .     'if(url.pathname===location.pathname&&url.hash)return;'
        //  Skip links that already go through ai_preview.php.
        .     'if(url.pathname.indexOf("ai_preview.php")!==-1)return;'
        .     'e.preventDefault();'
        //  Path is already relative to the site root for live-domain links.
        .     'var path=url.pathname.replace(/^\\/+/,"");'
        //  Notify the parent WHMCS frame of the page change.
        .     'try{parent.postMessage({type:"aisitemanager_navigate",path:path},"*");}catch(_){}'
        //  Navigate within the preview shim so staged files remain visible.
        .     'location.href="ai_preview.php?t="+encodeURIComponent(__tok)+"&path="+encodeURIComponent(path);'
        .   '});'
        . '}());'
        . '</script>';

    // Inject staging banner and nav script before </body>.
    $inject = $navScript . $stagingBanner;
    if (stripos($content, '</body>') !== false) {
        $content = str_ireplace('</body>', $inject . '</body>', $content);
    } else {
        $content .= $inject;
    }

    header('Content-Type: text/html; charset=utf-8');
    // Prevent browser caching of preview so edits always show fresh content.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $content;

} else {
    // Non-HTML files: serve as-is with appropriate content type.
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($filePath);
}
