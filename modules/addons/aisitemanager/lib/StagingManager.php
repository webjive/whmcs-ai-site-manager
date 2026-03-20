<?php
/**
 * AI Site Manager — Staging Manager
 * WebJIVE · https://web-jive.com
 *
 * Manages the lifecycle of the staging directory (.ai_staging/) on the customer's
 * server via FTP. Handles:
 *   - Checking whether staging is active (disk vs. database reconciliation)
 *   - Writing staged files
 *   - Committing staged changes to the live site
 *   - Discarding all staged changes
 *   - Generating and validating preview tokens
 *
 * All FTP paths are relative to the FTP account root, which is chrooted to
 * public_html by cPanel. So FTP path "/" = public_html/ on disk.
 *
 * DELETION MARKER: When a file is "deleted" via the AI, we write a special
 * marker string to the staging copy of that file. On commit, files containing
 * this marker cause the live counterpart to be deleted.
 */

namespace WHMCS\Module\Addon\AiSiteManager;

use WHMCS\Database\Capsule;

class StagingManager
{
    /**
     * Content written to a staging file to mark it as "delete on commit."
     * This string is unlikely to appear in real website files.
     */
    public const DELETION_MARKER = '__AISITEMANAGER_DELETE__';

    /** @var FtpClient */
    private FtpClient $ftp;

    /** @var string Name of the staging directory (e.g. '.ai_staging'). */
    private string $stagingDir;

    /** @var int WHMCS client ID owning this staging context. */
    private int $clientId;

    /**
     * @param FtpClient $ftp        An already-connected FTP client instance.
     * @param string    $stagingDir Staging directory name (from config).
     * @param int       $clientId   WHMCS client ID.
     */
    public function __construct(FtpClient $ftp, string $stagingDir, int $clientId)
    {
        $this->ftp        = $ftp;
        $this->stagingDir = '/' . ltrim($stagingDir, '/');
        $this->clientId   = $clientId;
    }

    // =========================================================================
    // Reconciliation
    // =========================================================================

    /**
     * Reconcile the database staging_active flag against actual disk state.
     *
     * Called on every client area page load to handle edge cases such as:
     *   - JetBackup restoring a site (staging dir reappears on disk).
     *   - Admin manually deleting staging via cPanel File Manager.
     *
     * Updates the database to match disk reality and returns the true state.
     *
     * @return bool  True if staging directory exists on disk.
     */
    public function reconcile(): bool
    {
        $diskHasStaging = $this->ftp->directoryExists($this->stagingDir);
        $dbRecord = Capsule::table('mod_aisitemanager_accounts')
            ->where('whmcs_client_id', $this->clientId)
            ->first(['staging_active']);

        if (!$dbRecord) {
            return false;
        }

        $dbStagingActive = (bool)$dbRecord->staging_active;

        if ($diskHasStaging !== $dbStagingActive) {
            // Disk and DB disagree — update DB to match disk truth.
            Capsule::table('mod_aisitemanager_accounts')
                ->where('whmcs_client_id', $this->clientId)
                ->update(['staging_active' => $diskHasStaging ? 1 : 0]);
        }

        return $diskHasStaging;
    }

    /**
     * Check whether staging is active (disk state, not DB).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->ftp->directoryExists($this->stagingDir);
    }

    // =========================================================================
    // Staging initialization
    // =========================================================================

    /**
     * Ensure the staging directory exists and is properly secured.
     *
     * Creates .ai_staging/ and its .htaccess protection if they do not exist.
     * Safe to call on every write — only creates if missing.
     *
     * @throws \RuntimeException on FTP failure.
     */
    public function initialize(): void
    {
        if (!$this->ftp->directoryExists($this->stagingDir)) {
            $this->ftp->createDirectory($this->stagingDir);
            // Write an .htaccess that denies all direct HTTP access.
            $this->ftp->writeFile(
                $this->stagingDir . '/.htaccess',
                "Order Deny,Allow\nDeny from all\n"
            );
        }

        // Update DB flag.
        Capsule::table('mod_aisitemanager_accounts')
            ->where('whmcs_client_id', $this->clientId)
            ->update(['staging_active' => 1]);
    }

    // =========================================================================
    // File operations (write to staging, never to live)
    // =========================================================================

    /**
     * Write a file to the staging area.
     *
     * The user-supplied path is relative to public_html root (e.g. "index.html").
     * This method prepends the staging directory so the write always lands in
     * .ai_staging/ regardless of what Claude passes as the path.
     *
     * @param  string $relativePath Path relative to public_html (e.g. "css/style.css").
     * @param  string $content      File contents to stage.
     * @throws \RuntimeException on FTP failure.
     */
    public function writeFile(string $relativePath, string $content): void
    {
        $this->validateRelativePath($relativePath);
        $this->initialize();

        $stagingPath = $this->stagingDir . '/' . ltrim($relativePath, '/');
        $this->ftp->writeFile($stagingPath, $content);
    }

    /**
     * Mark a file for deletion on commit.
     *
     * Creates a staging placeholder containing DELETION_MARKER. On commit,
     * the live file at this path is deleted instead of being overwritten.
     *
     * @param  string $relativePath Path relative to public_html.
     * @throws \RuntimeException on FTP failure.
     */
    public function markForDeletion(string $relativePath): void
    {
        $this->validateRelativePath($relativePath);
        $this->initialize();

        $stagingPath = $this->stagingDir . '/' . ltrim($relativePath, '/');
        $this->ftp->writeFile($stagingPath, self::DELETION_MARKER);
    }

    /**
     * Create a directory within the staging area.
     *
     * @param  string $relativePath Directory path relative to public_html.
     * @throws \RuntimeException on FTP failure.
     */
    public function createDirectory(string $relativePath): void
    {
        $this->validateRelativePath($relativePath);
        $this->initialize();

        $stagingPath = $this->stagingDir . '/' . ltrim($relativePath, '/');
        $this->ftp->createDirectory($stagingPath);
    }

    /**
     * Read a file, preferring the staged version over the live version.
     *
     * Claude always sees the most recent state of a file, including its own
     * pending changes, ensuring consistent multi-step edits in one session.
     *
     * @param  string $relativePath Path relative to public_html.
     * @param  int    $maxBytes     Maximum bytes to read.
     * @return string               File contents.
     * @throws \RuntimeException    if neither staged nor live file exists.
     */
    public function readFile(string $relativePath, int $maxBytes = 524288): string
    {
        $this->validateRelativePath($relativePath);
        $cleanPath   = '/' . ltrim($relativePath, '/');
        $stagingPath = $this->stagingDir . $cleanPath;

        // Prefer staged version if it exists and is not a deletion marker.
        if ($this->ftp->fileExists($stagingPath)) {
            $content = $this->ftp->readFile($stagingPath, $maxBytes);
            if ($content !== self::DELETION_MARKER) {
                return $content;
            }
            throw new \RuntimeException(
                "File '{$relativePath}' is marked for deletion in staging."
            );
        }

        // Fall back to live file.
        return $this->ftp->readFile($cleanPath, $maxBytes);
    }

    // =========================================================================
    // Commit
    // =========================================================================

    /**
     * Commit all staged changes to the live site.
     *
     * Copies every file from .ai_staging/ to its live counterpart in public_html.
     * Files containing DELETION_MARKER cause their live counterparts to be deleted.
     * After all files are processed, .ai_staging/ is completely removed.
     *
     * @return array Log of actions taken: [['action' => 'published'|'deleted', 'path' => '...'], ...]
     * @throws \RuntimeException on FTP failure.
     */
    public function commit(): array
    {
        if (!$this->ftp->directoryExists($this->stagingDir)) {
            return []; // Nothing to commit.
        }

        $log = [];
        // Copy staging tree onto live tree (recursive, handles deletions).
        $this->ftp->copyDirectoryRecursive($this->stagingDir, '/', $log);

        // Remove the staging directory entirely.
        $this->ftp->deleteDirectoryRecursive($this->stagingDir);

        // Update DB flags.
        Capsule::table('mod_aisitemanager_accounts')
            ->where('whmcs_client_id', $this->clientId)
            ->update([
                'staging_active'       => 0,
                'preview_token'        => null,
                'preview_token_expiry' => null,
            ]);

        return $log;
    }

    // =========================================================================
    // Discard
    // =========================================================================

    /**
     * Discard all staged changes by deleting .ai_staging/ entirely.
     *
     * @throws \RuntimeException on FTP failure.
     */
    public function discard(): void
    {
        if ($this->ftp->directoryExists($this->stagingDir)) {
            $this->ftp->deleteDirectoryRecursive($this->stagingDir);
        }

        // Update DB flags.
        Capsule::table('mod_aisitemanager_accounts')
            ->where('whmcs_client_id', $this->clientId)
            ->update([
                'staging_active'       => 0,
                'preview_token'        => null,
                'preview_token_expiry' => null,
            ]);
    }

    // =========================================================================
    // Preview token
    // =========================================================================

    /**
     * Generate (or refresh) a preview token for the staging iframe.
     *
     * Tokens expire after $ttl seconds. The token is stored in the database
     * and also written to .ai_staging/.preview_token on disk so ai_preview.php
     * can validate it without needing database credentials.
     *
     * @param  int    $ttl  Token lifetime in seconds (default 8 hours).
     * @return string       The new preview token.
     * @throws \RuntimeException on FTP failure.
     */
    public function generatePreviewToken(int $ttl = 28800, string $siteDomain = '', string $siteMode = 'construction'): string
    {
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + $ttl);

        // Write token file to staging dir FIRST (creates staging if not present).
        // The DB is only updated after the disk write succeeds. This prevents a
        // mismatch where the DB holds a new token but the disk file still has the
        // old expired one — which would cause a permanent 403 on every page load
        // until manually repaired.
        //
        // site_domain is stored so ai_preview.php can inject the correct <base>
        // tag pointing to the live domain (e.g. giraffetree.com) rather than the
        // server tilde URL — this ensures all relative assets (CSS, JS, images)
        // resolve from the live site, not from earth1.webjive.net/~user/.
        $this->initialize();
        $this->ftp->writeFile(
            $this->stagingDir . '/.preview_token',
            json_encode([
                'token'       => $token,
                'expiry'      => strtotime($expiry),
                'site_domain' => $siteDomain,
                'site_mode'   => $siteMode,
            ])
        );

        // FTP write succeeded — now persist the token to the DB.
        Capsule::table('mod_aisitemanager_accounts')
            ->where('whmcs_client_id', $this->clientId)
            ->update([
                'preview_token'        => $token,
                'preview_token_expiry' => $expiry,
            ]);

        return $token;
    }

    /**
     * Validate a preview token from ai_preview.php.
     *
     * Checks both the database record and that it has not expired.
     *
     * @param  string $token  The token string from the URL/cookie.
     * @return bool           True if token is valid and not expired.
     */
    public function validatePreviewToken(string $token): bool
    {
        $record = Capsule::table('mod_aisitemanager_accounts')
            ->where('whmcs_client_id', $this->clientId)
            ->where('preview_token', $token)
            ->first(['preview_token_expiry']);

        if (!$record || !$record->preview_token_expiry) {
            return false;
        }

        return strtotime($record->preview_token_expiry) > time();
    }

    // =========================================================================
    // Path validation
    // =========================================================================

    /**
     * Validate that a relative path is safe to use.
     *
     * Rejects any path containing '..' or resolving outside public_html.
     *
     * @param  string $path The relative path to validate.
     * @throws \InvalidArgumentException on unsafe path.
     */
    private function validateRelativePath(string $path): void
    {
        if (strpos($path, '..') !== false) {
            throw new \InvalidArgumentException(
                "Path traversal attempt in path: '{$path}'"
            );
        }

        // Reject paths that try to escape the staging subdir.
        if (strpos($path, $this->stagingDir) === 0) {
            throw new \InvalidArgumentException(
                "Path must not start with the staging directory name. " .
                "Use paths relative to public_html root."
            );
        }
    }
}
