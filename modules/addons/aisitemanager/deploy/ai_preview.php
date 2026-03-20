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
    // When served via a cPanel tilde URL (https://server/~user/ai_preview.php) we
    // must include the tilde directory prefix in the base; otherwise all relative
    // assets resolve to https://server/asset.css instead of https://server/~user/asset.css
    // and return 404 — which is the root cause of the blank/broken preview.
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? '';
    $scriptDir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/ai_preview.php'), '/');
    $basePath   = $scriptDir . '/';  // e.g. '/~cpaneluser/' or '/'
    $baseUrl    = $scheme . '://' . $host . $basePath;

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
    // Intercepts same-origin link clicks, keeps navigation inside ai_preview.php
    // (so staged files continue to be shown), and notifies the parent WHMCS frame
    // via postMessage so it can update its currentPath state.
    $navScript = '<script>'
        . '(function(){'
        .   'var __tok=' . json_encode($tokenParam) . ';'
        // Base path of this script, e.g. '/~cpaneluser/' or '/'.
        // Used to strip the tilde prefix so paths sent to the parent frame
        // remain relative to public_html root (e.g. 'about.html', not '~user/about.html').
        .   'var __base=' . json_encode($basePath) . ';'
        .   'document.addEventListener("click",function(e){'
        .     'var a=e.target.closest("a");'
        .     'if(!a||!a.href)return;'
        .     'var url;try{url=new URL(a.href);}catch(_){return;}'
        //  Skip off-site links (external domains).
        .     'if(url.host!==location.host)return;'
        //  Skip hash-only same-page anchors.
        .     'if(url.pathname===location.pathname&&url.hash)return;'
        //  Skip links that already go through ai_preview.php.
        .     'if(url.pathname.indexOf("ai_preview.php")!==-1)return;'
        .     'e.preventDefault();'
        //  Strip tilde prefix (e.g. /~cpaneluser/) to get the public_html-relative path.
        .     'var path=url.pathname;'
        .     'if(__base.length>1&&path.indexOf(__base)===0){'
        .       'path=path.slice(__base.length);'
        .     '}else{'
        .       'path=path.replace(/^\\/+/,"");'
        .     '}'
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
