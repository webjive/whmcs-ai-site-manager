# Changelog

All notable changes to AI Site Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2024-01-01

### Added
- Initial release of AI Site Manager for WHMCS.
- Two-panel client area interface: AI chat (left) and live staging preview (right).
- Staging system: all AI file writes go to `public_html/.ai_staging/` before committing.
- Five AI tools: `list_directory`, `read_file`, `write_file`, `create_directory`, `delete_file`.
- Commit and Discard workflow for publishing or abandoning staged changes.
- WHMCS session-validated staging preview shim (`ai_preview.php`) deployed to customer sites.
- Persistent chat history stored in WHMCS database.
- Admin panel: accounts list with per-account enable/disable and provision/deprovision.
- Admin panel: TinyMCE WYSIWYG editor for client-area header instructions.
- Admin panel: Anthropic API key management.
- `AfterModuleCreate` hook for automatic provisioning on new hosting account creation.
- Three-layer staging security: robots.txt exclusion, .htaccess deny, session-validated shim.
- JetBackup reconciliation: automatic mismatch correction between disk and database state.
- FTPS-based file operations (hosting-panel agnostic).
- Path traversal protection on all file operations.
- Encrypted FTP credential storage using WHMCS built-in encryption.

---

<!-- Add future releases above this line. -->
<!-- Format: ## [X.Y.Z] - YYYY-MM-DD -->
