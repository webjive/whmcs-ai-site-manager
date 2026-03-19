<?php
/**
 * AI Site Manager — FTPS Client
 * WebJIVE · https://web-jive.com
 *
 * Wraps PHP's built-in FTP extension with SSL support, passive mode, and
 * path safety validation. All public methods validate that the target path
 * remains within the FTP account's root directory — no traversal is possible.
 *
 * The FTP sub-account created during provisioning is scoped to public_html,
 * so FTP path "/" maps to the customer's public_html on disk. Path security
 * is enforced both by the FTP account chroot AND by this class.
 *
 * Requires PHP's ext-ftp extension with SSL support (ftp_ssl_connect).
 */

namespace WHMCS\Module\Addon\AiSiteManager;

class FtpClient
{
    /** @var resource|null Active FTP connection handle */
    private $connection = null;

    /** @var string FTP hostname */
    private string $host;

    /** @var int FTP port */
    private int $port;

    /** @var string FTP username */
    private string $username;

    /** @var string FTP password (plaintext, decrypted before passing here) */
    private string $password;

    /** @var int Connection timeout in seconds */
    private int $timeout;

    /** @var string The FTP working root (always '/') — kept for clarity */
    private const FTP_ROOT = '/';

    /**
     * @param string $host     FTP server hostname or IP.
     * @param int    $port     FTP port (21 for explicit TLS, 990 for implicit).
     * @param string $username FTP login username.
     * @param string $password FTP password in plaintext.
     * @param int    $timeout  Connection timeout in seconds.
     */
    public function __construct(
        string $host,
        int    $port,
        string $username,
        string $password,
        int    $timeout = 30
    ) {
        $this->host     = $host;
        $this->port     = $port;
        $this->username = $username;
        $this->password = $password;
        $this->timeout  = $timeout;
    }

    // =========================================================================
    // Connection management
    // =========================================================================

    /**
     * Open an SSL-encrypted FTP connection and log in.
     *
     * Tries ftp_ssl_connect() first (implicit/explicit TLS). Falls back to
     * plain ftp_connect() only if the SSL extension is unavailable — this
     * should not happen on any modern cPanel server.
     *
     * @throws \RuntimeException on connection or login failure.
     */
    public function connect(): void
    {
        if ($this->connection !== null) {
            return; // Already connected.
        }

        // Prefer SSL; fall back if ext-ftp was compiled without SSL support.
        if (function_exists('ftp_ssl_connect')) {
            $conn = @ftp_ssl_connect($this->host, $this->port, $this->timeout);
        } else {
            $conn = @ftp_connect($this->host, $this->port, $this->timeout);
        }

        if ($conn === false) {
            throw new \RuntimeException(
                "FTP: Could not connect to {$this->host}:{$this->port}. " .
                "Check the hostname, port, and that the FTP service is running."
            );
        }

        if (!@ftp_login($conn, $this->username, $this->password)) {
            ftp_close($conn);
            throw new \RuntimeException(
                "FTP: Login failed for user '{$this->username}'. " .
                "Verify FTP credentials in the AI Site Manager accounts table."
            );
        }

        // Passive mode is required in most hosting environments where the FTP
        // server is behind NAT/firewall and cannot initiate data connections.
        ftp_pasv($conn, true);

        $this->connection = $conn;
    }

    /**
     * Close the FTP connection gracefully.
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Ensure we have an active connection, throw if not.
     *
     * @throws \RuntimeException if connect() has not been called.
     */
    private function requireConnection(): void
    {
        if ($this->connection === null) {
            throw new \RuntimeException('FTP: Not connected. Call connect() first.');
        }
    }

    // =========================================================================
    // Path safety
    // =========================================================================

    /**
     * Sanitize and validate a user-supplied path.
     *
     * Rules enforced:
     *   1. Must not contain '..' segments (traversal prevention).
     *   2. After normalization, must start with '/' (FTP absolute path).
     *   3. May be an empty string or '/', which resolves to the FTP root.
     *
     * Because the FTP account is chrooted to public_html by cPanel, a path of
     * '/' here literally means public_html/ on disk. No further restriction is
     * needed at this layer for read operations. Write operations additionally
     * restrict paths to .ai_staging/ — enforced in StagingManager/ClaudeProxy.
     *
     * @param  string $path The raw path from the caller.
     * @return string       The cleaned, absolute FTP path.
     * @throws \InvalidArgumentException on traversal attempt.
     */
    public function sanitizePath(string $path): string
    {
        // Reject any '..' component regardless of encoding.
        if (strpos($path, '..') !== false) {
            throw new \InvalidArgumentException(
                "Path traversal attempt detected in path: '{$path}'"
            );
        }

        // Normalize slashes and resolve single-dot segments.
        $parts  = explode('/', str_replace('\\', '/', $path));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $result[] = $part;
        }

        return '/' . implode('/', $result);
    }

    // =========================================================================
    // Directory operations
    // =========================================================================

    /**
     * List the contents of a directory.
     *
     * Returns an array of entries, each with:
     *   - name  (string)  Filename or directory name.
     *   - type  (string)  'file' or 'dir'.
     *   - size  (int)     File size in bytes (0 for directories).
     *
     * @param  string $path FTP path of the directory to list.
     * @return array        Array of entry arrays.
     * @throws \RuntimeException if the directory cannot be listed.
     */
    public function listDirectory(string $path): array
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        // ftp_rawlist returns lines in `ls -la` format; parse type + name.
        $rawLines = @ftp_rawlist($this->connection, $safePath);

        if ($rawLines === false) {
            throw new \RuntimeException("FTP: Could not list directory '{$safePath}'.");
        }

        $entries = [];
        foreach ($rawLines as $line) {
            // Skip total line and current/parent directory entries.
            if (preg_match('/^total/', $line) || preg_match('/\s+\.\.?$/', $line)) {
                continue;
            }

            // Parse the ls -la line: permissions, links, owner, group, size, month, day, time/year, name
            // Example: drwxr-xr-x   2 user group  4096 Jan  1 12:00 dirname
            if (!preg_match(
                '/^([\-dlrwx]{10})\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+(.+)$/',
                $line,
                $matches
            )) {
                continue; // Skip unrecognized format lines.
            }

            $permissions = $matches[1];
            $size        = (int)$matches[2];
            $name        = trim($matches[3]);

            // First character of permissions: 'd' = directory, '-' = file, 'l' = symlink.
            $type = ($permissions[0] === 'd') ? 'dir' : 'file';

            $entries[] = [
                'name' => $name,
                'type' => $type,
                'size' => ($type === 'file') ? $size : 0,
            ];
        }

        return $entries;
    }

    /**
     * Create a directory (and all intermediate directories) via FTP.
     *
     * @param  string $path FTP path of the directory to create.
     * @throws \RuntimeException on failure.
     */
    public function createDirectory(string $path): void
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        // Build the path segment by segment so parent directories are created.
        $parts   = array_filter(explode('/', $safePath));
        $current = '';
        foreach ($parts as $segment) {
            $current .= '/' . $segment;
            if (!$this->directoryExists($current)) {
                if (!@ftp_mkdir($this->connection, $current)) {
                    throw new \RuntimeException(
                        "FTP: Could not create directory '{$current}'."
                    );
                }
            }
        }
    }

    /**
     * Check whether a path is an existing directory on the FTP server.
     *
     * @param  string $path FTP path to check.
     * @return bool         True if directory exists, false otherwise.
     */
    public function directoryExists(string $path): bool
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        // Save current directory, attempt to change, restore.
        $current = @ftp_pwd($this->connection);
        $exists  = @ftp_chdir($this->connection, $safePath);
        if ($exists && $current !== false) {
            @ftp_chdir($this->connection, $current);
        }
        return $exists;
    }

    /**
     * Delete a directory and all of its contents recursively.
     *
     * @param  string $path FTP path of the directory to remove.
     * @throws \RuntimeException if any file or directory cannot be deleted.
     */
    public function deleteDirectoryRecursive(string $path): void
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        // List contents, delete files, recurse into subdirectories.
        $items = @ftp_nlist($this->connection, $safePath);
        if ($items === false) {
            // Directory may already not exist; treat as success.
            return;
        }

        foreach ($items as $item) {
            // ftp_nlist may return full paths or just names depending on server.
            $itemName = basename($item);
            if ($itemName === '.' || $itemName === '..') {
                continue;
            }
            $itemPath = rtrim($safePath, '/') . '/' . $itemName;

            if ($this->directoryExists($itemPath)) {
                $this->deleteDirectoryRecursive($itemPath);
            } else {
                @ftp_delete($this->connection, $itemPath);
            }
        }

        // Remove the now-empty directory.
        @ftp_rmdir($this->connection, $safePath);
    }

    // =========================================================================
    // File operations
    // =========================================================================

    /**
     * Read the contents of a remote file into a string.
     *
     * Uses an in-memory stream (php://temp) to avoid writing to the local disk.
     *
     * @param  string $path         FTP path of the file to read.
     * @param  int    $maxBytes     Maximum bytes to read (default 512 KB).
     * @return string               File contents as a string.
     * @throws \RuntimeException    if the file cannot be read.
     * @throws \OverflowException   if the file exceeds $maxBytes.
     */
    public function readFile(string $path, int $maxBytes = 524288): string
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        $temp = fopen('php://temp', 'r+');
        if (!$temp) {
            throw new \RuntimeException('FTP: Could not open in-memory stream for file read.');
        }

        $result = @ftp_fget($this->connection, $temp, $safePath, FTP_BINARY);
        if (!$result) {
            fclose($temp);
            throw new \RuntimeException("FTP: Could not read file '{$safePath}'.");
        }

        // Check size before reading into memory.
        $size = ftell($temp);
        if ($size > $maxBytes) {
            fclose($temp);
            throw new \OverflowException(
                "FTP: File '{$safePath}' is {$size} bytes, exceeding the {$maxBytes} byte limit."
            );
        }

        rewind($temp);
        $content = stream_get_contents($temp);
        fclose($temp);

        return $content !== false ? $content : '';
    }

    /**
     * Write a string to a remote file, creating intermediate directories as needed.
     *
     * @param  string $path    FTP path of the destination file.
     * @param  string $content File contents to write.
     * @throws \RuntimeException on failure.
     */
    public function writeFile(string $path, string $content): void
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        // Ensure the parent directory exists.
        $parent = dirname($safePath);
        if ($parent !== '/' && !$this->directoryExists($parent)) {
            $this->createDirectory($parent);
        }

        $temp = fopen('php://temp', 'r+');
        if (!$temp) {
            throw new \RuntimeException('FTP: Could not open in-memory stream for file write.');
        }

        fwrite($temp, $content);
        rewind($temp);

        $result = @ftp_fput($this->connection, $safePath, $temp, FTP_BINARY);
        fclose($temp);

        if (!$result) {
            throw new \RuntimeException("FTP: Could not write file '{$safePath}'.");
        }
    }

    /**
     * Delete a single file from the FTP server.
     *
     * @param  string $path FTP path of the file to delete.
     * @throws \RuntimeException on failure.
     */
    public function deleteFile(string $path): void
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);

        if (!@ftp_delete($this->connection, $safePath)) {
            throw new \RuntimeException("FTP: Could not delete file '{$safePath}'.");
        }
    }

    /**
     * Check whether a file exists on the FTP server.
     *
     * ftp_size() returns -1 if the path does not exist or is a directory.
     *
     * @param  string $path FTP path to check.
     * @return bool         True if the file exists.
     */
    public function fileExists(string $path): bool
    {
        $this->requireConnection();
        $safePath = $this->sanitizePath($path);
        return @ftp_size($this->connection, $safePath) !== -1;
    }

    /**
     * Move (rename) a file or directory on the FTP server.
     *
     * @param  string $from Source FTP path.
     * @param  string $to   Destination FTP path.
     * @throws \RuntimeException on failure.
     */
    public function rename(string $from, string $to): void
    {
        $this->requireConnection();
        $safeFrom = $this->sanitizePath($from);
        $safeTo   = $this->sanitizePath($to);

        if (!@ftp_rename($this->connection, $safeFrom, $safeTo)) {
            throw new \RuntimeException(
                "FTP: Could not move '{$safeFrom}' to '{$safeTo}'."
            );
        }
    }

    /**
     * Recursively copy all files from one FTP directory to another.
     *
     * Used during Commit to move staged files to their live locations.
     * Files with content equal to the DELETION_MARKER constant are skipped
     * (they are handled by StagingManager to delete the live counterpart).
     *
     * @param  string $fromDir Source FTP directory path.
     * @param  string $toDir   Destination FTP directory path.
     * @param  array  &$log    Reference to a log array; entries are appended.
     * @throws \RuntimeException on any file operation failure.
     */
    public function copyDirectoryRecursive(string $fromDir, string $toDir, array &$log = []): void
    {
        $this->requireConnection();

        $entries = $this->listDirectory($fromDir);

        foreach ($entries as $entry) {
            // Skip ALL hidden/dot files. This intentionally includes .htaccess:
            // the staging root contains an .htaccess with "Deny from all" to
            // protect .ai_staging/ from direct HTTP access. That file must never
            // be published to the live site root (it would block the entire site).
            // .htaccess edits are not supported via AI in v1.0.
            if ($entry['name'][0] === '.') {
                continue;
            }

            $srcPath  = rtrim($fromDir, '/') . '/' . $entry['name'];
            $destPath = rtrim($toDir,   '/') . '/' . $entry['name'];

            if ($entry['type'] === 'dir') {
                // Ensure destination directory exists, then recurse.
                if (!$this->directoryExists($destPath)) {
                    $this->createDirectory($destPath);
                }
                $this->copyDirectoryRecursive($srcPath, $destPath, $log);
            } else {
                // Read the staged file and check for deletion markers.
                $content = $this->readFile($srcPath);

                if ($content === StagingManager::DELETION_MARKER) {
                    // This file is marked for deletion in live.
                    if ($this->fileExists($destPath)) {
                        $this->deleteFile($destPath);
                        $log[] = ['action' => 'deleted', 'path' => $destPath];
                    }
                } else {
                    // Normal file: write to live location.
                    $this->writeFile($destPath, $content);
                    $log[] = ['action' => 'published', 'path' => $destPath];
                }
            }
        }
    }
}
