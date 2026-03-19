# AI Site Manager — WHMCS Addon Module

**Version:** 1.0.0
**Author:** WebJIVE · [web-jive.com](https://web-jive.com)
**License:** Proprietary commercial — see [LICENSE.md](LICENSE.md)
**Marketplace:** Distributed through the WHMCS Marketplace

---

## Overview

AI Site Manager embeds a two-panel website editing interface in the WHMCS client area. Customers make plain-language requests in a chat window and Claude AI edits their website files. All changes go to a **staging area** first and are not live until the customer clicks **Commit**.

**Left panel (40%):** AI chat interface
**Right panel (60%):** Live staging preview iframe

---

## Requirements

| Requirement | Minimum |
|---|---|
| WHMCS | 8.x |
| PHP | 7.4 (8.x recommended) |
| PHP ext-ftp | With SSL support (ftp_ssl_connect) |
| PHP ext-curl | With SSL support |
| PHP ext-openssl | Required by WHMCS encryption |
| Hosting panel | cPanel/WHM (for auto-provisioning) |
| Anthropic API key | From [console.anthropic.com](https://console.anthropic.com) |

> **Hosting-panel agnostic for file operations:** All file I/O uses FTPS. The WHM API is used only for provisioning the FTP sub-account. Manual provisioning (entering credentials directly) is available if WHM is not accessible.

---

## Installation

### Step 1 — Upload files

Copy the `modules/` directory from this repository into your WHMCS root so that the structure is:

```
/path/to/whmcs/modules/addons/aisitemanager/
```

The WHMCS root is wherever `init.php` lives.

### Step 2 — Activate the module in WHMCS

1. Log into the WHMCS admin panel.
2. Navigate to **Setup → Addon Modules**.
3. Find **AI Site Manager** and click **Activate**.
4. WHMCS creates the three database tables and **auto-generates `config.php`** with a unique encryption key. No manual config file setup required.

> **To customise defaults** (FTP port, timeouts, staging dir, etc.) edit the generated
> `modules/addons/aisitemanager/config.php` after activation. See `config.sample.php` for a
> full reference of every available setting.

### Step 3 — Enter the Anthropic API key

1. In WHMCS admin, go to **Addons → AI Site Manager**.
2. Click the **Settings** tab.
3. Paste your Anthropic API key into the **Anthropic API Key** field.
4. Click **Save Settings**.

The API key is stored in the WHMCS database and is **never** exposed to the client browser.

---

## Provisioning Your First Account

### Automatic provisioning

When a new hosting account is created in WHMCS (via **Orders → Accept Order** or the module provisioning flow), the `AfterModuleCreate` hook fires automatically and provisions AI Site Manager for that account — creating the FTP sub-account, deploying `ai_preview.php`, and setting up the staging directory.

**Prerequisites for auto-provisioning:**
- `config.php` must exist and be correctly configured.
- The Anthropic API key must be saved in the Settings tab.
- The WHMCS server must have access to the cPanel WHM API (same network as the hosting server).

### Manual provisioning

If an account was created before the module was installed:

1. Go to **Addons → AI Site Manager → Accounts** tab.
2. Find the customer in the table.
3. Click **Provision** next to their account.

This runs the same provisioning routine as the hook — creates the FTP sub-account, deploys `ai_preview.php`, sets up `.ai_staging/` with `.htaccess` protection, and updates `robots.txt`.

---

## Configuring the Header Instructions

The header instructions appear at the top of the chat panel for all customers. It's collapsible and supports formatted HTML.

1. Go to **Addons → AI Site Manager → Settings** tab.
2. Use the **TinyMCE** editor in the **Client Area Header Content** field.
3. Add instructions, getting-started tips, and hyperlinks to tutorial videos.
4. Click **Save Settings**.

**Tip:** Link to short Loom or YouTube tutorial videos explaining how to commit, how to describe changes, etc. Customers who read this are less likely to contact support.

---

## Customer Workflow

1. Customer logs into WHMCS client area and opens **AI Site Manager**.
2. The left panel shows instructions and a chat history.
3. Customer types a request (e.g., "Update my phone number on the contact page to 555-1234").
4. Claude reads the relevant file, makes the change, and writes it to `.ai_staging/`.
5. The preview iframe refreshes to show the staged version.
6. Customer reviews the change, then clicks **Commit** to publish or **Discard** to cancel.

### Returning customers with uncommitted changes

If a customer has staged-but-uncommitted changes from a previous session:
- An orange banner appears: *"You have uncommitted changes from your last session."*
- The preview iframe automatically loads the staged version.
- Commit and Discard buttons are active immediately.
- Previous chat history is restored.

---

## Staging Security

Three layers prevent direct public access to staged files:

| Layer | Mechanism |
|---|---|
| **1. robots.txt** | `Disallow: /.ai_staging/` added on provisioning |
| **2. .htaccess** | `Deny from all` inside `.ai_staging/` directory |
| **3. Preview shim** | `ai_preview.php` validates a time-limited token before serving any content |

The preview shim returns **HTTP 403** if the token is missing, invalid, or expired. The token is stored in `.ai_staging/.preview_token` and in the WHMCS database. Tokens expire after 8 hours (configurable via `preview_token_ttl`).

---

## JetBackup Configuration

> **Important:** Read this section if you use JetBackup on your cPanel servers.

### Exclude the staging directory from backups

JetBackup may back up `.ai_staging/` directories, which contain temporary unstaged files and should not be in production backups.

**In JetBackup, add the following exclusion pattern for each backup job:**

```
public_html/.ai_staging
```

Or use a glob pattern if your JetBackup version supports it:

```
**/.ai_staging/**
```

Steps:
1. Log into WHM → JetBackup.
2. Edit each active backup job.
3. Under **Exclusions**, add `.ai_staging` to the excluded paths.
4. Save the backup job.

### Why chat history is safe from JetBackup restores

Chat messages are stored in the WHMCS database (`mod_aisitemanager_chat_history`), not in any cPanel file. They follow the WHMCS database backup schedule and are unaffected by cPanel/JetBackup file restores.

### Automatic reconciliation after a restore

If JetBackup restores a site to a snapshot that included a `.ai_staging/` directory, AI Site Manager handles this automatically:

- On the customer's next page load, the module checks whether `.ai_staging/` exists on disk via FTP.
- If the disk state disagrees with the database flag, the database is corrected to match disk reality.
- If staging is found on disk but not in the database → `staging_active` is set to `true` and the uncommitted-changes banner appears.
- If the database says staging is active but the directory is gone → `staging_active` is set to `false` silently.

No manual intervention is needed after a restore.

---

## GitHub Deployment Notes

### Cloning to a live server

This repository is structured to mirror the WHMCS file system. After cloning, copy (or symlink) the `modules/` directory into your WHMCS root:

```bash
git clone https://github.com/webjive/ai-site-manager.git
cp -r ai-site-manager/modules/ /path/to/whmcs/
```

Or if you clone directly into the WHMCS root:

```bash
cd /path/to/whmcs
git clone https://github.com/webjive/ai-site-manager.git .
```

### Files that must NOT be in the repository

The `.gitignore` excludes:
- `modules/addons/aisitemanager/config.php` — contains the encryption key
- `vendor/` — Composer dependencies (none in v1.0)
- `.DS_Store`, `*.log`, `.env*`

**Never commit `config.php`** — it contains the encryption key for all stored FTP credentials.

### Updating the module

1. Pull the latest changes from the repository.
2. If the version number changed, WHMCS will prompt you to run the upgrade function from the Addon Modules page. This runs database migrations automatically.

---

## File Structure

```
modules/addons/aisitemanager/
├── aisitemanager.php       Main module (config, activate, admin output, client area)
├── hooks.php               WHMCS hooks (AfterModuleCreate, AfterModuleTerminate)
├── ajax.php                Browser-facing AJAX endpoint (chat, commit, discard)
├── config.php              Installation-specific config (gitignored — copy from .sample)
├── config.sample.php       Sample config with placeholder values
├── lang/
│   └── english.php         Language strings
├── lib/
│   ├── Encryption.php      Wraps WHMCS encrypt_db_data / decrypt_db_data
│   ├── FtpClient.php       FTPS operations with path safety validation
│   ├── StagingManager.php  Staging lifecycle (init, write, commit, discard, tokens)
│   └── ClaudeProxy.php     Anthropic API integration and tool-use loop
├── templates/
│   └── clientarea.tpl      Smarty template — two-panel chat/preview layout
├── assets/
│   ├── css/
│   │   └── aisitemanager.css   Client area styles
│   └── js/
│       └── aisitemanager.js    Chat UI (vanilla JS)
├── sql/
│   └── install.sql         Reference SQL for manual table creation
└── deploy/
    └── ai_preview.php      Staging preview shim — deployed to customer's public_html/
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `mod_aisitemanager_accounts` | One row per provisioned account: FTP credentials (encrypted), AI status, staging flag |
| `mod_aisitemanager_chat_history` | Full conversation history per client |
| `mod_aisitemanager_settings` | Global settings: `api_key`, `header_wysiwyg_content` |

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Provisioning fails: "config.php not found" | `config.php` was not created | Copy `config.sample.php` to `config.php` and fill in all values |
| Provisioning fails: "WHM API error" | WHM credentials incorrect or network issue | Check `tblservers` accesshash; verify WHM is reachable from WHMCS server |
| FTP login fails | FTP sub-account password changed externally | Deprovision and re-provision the account |
| Chat returns "not configured" | API key not saved | Enter API key in Settings tab and save |
| Preview iframe shows 403 | Preview token expired | Refresh the AI Site Manager page to generate a new token |
| Staging active but no files to commit | JetBackup restored old staging dir | Click Discard to clear stale staging, then continue |
| `encrypt_db_data` not found | WHMCS not bootstrapped | Ensure `ajax.php` bootstraps via the correct path to `init.php` |

---

## Support

For support, bug reports, or feature requests, contact WebJIVE:
**Website:** [https://web-jive.com](https://web-jive.com)

For WHMCS Marketplace license issues, use the WHMCS Marketplace support channels.
