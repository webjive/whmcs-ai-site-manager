-- =============================================================================
-- AI Site Manager — Database Installation Script
-- WebJIVE · https://web-jive.com
--
-- This script is provided for reference. The WHMCS module activation function
-- creates these tables automatically via Capsule ORM.
-- You only need to run this manually if installing without the WHMCS addon
-- activation flow (e.g., restoring a database from backup).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- mod_aisitemanager_accounts
-- One row per hosting account that has been provisioned for AI Site Manager.
-- Stores the dedicated FTP sub-account credentials (encrypted) and status flags.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_aisitemanager_accounts` (
    `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
    `whmcs_client_id`      INT(11)      NOT NULL COMMENT 'References tblclients.id',
    `whmcs_service_id`     INT(11)      NOT NULL COMMENT 'References tblhosting.id',
    `cpanel_username`      VARCHAR(64)  NOT NULL COMMENT 'cPanel account username',
    `ftp_username`         VARCHAR(128) NOT NULL COMMENT 'FTP sub-account login (user@domain.com format)',
    `ftp_password`         TEXT         NOT NULL COMMENT 'FTP password, encrypted via WHMCS encrypt_db_data()',
    `ftp_host`             VARCHAR(255) NOT NULL COMMENT 'Hostname or IP for FTP connection',
    `ftp_port`             INT(5)       NOT NULL DEFAULT 21 COMMENT 'FTP port (21=explicit TLS, 990=implicit TLS)',
    `ai_enabled`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=AI chat active for this account',
    `staging_active`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=.ai_staging/ directory exists with uncommitted files',
    `preview_token`        VARCHAR(64)      NULL COMMENT 'Time-limited token for staging preview iframe auth',
    `preview_token_expiry` DATETIME         NULL COMMENT 'UTC expiry for preview_token',
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_client_id` (`whmcs_client_id`),
    KEY `idx_service_id` (`whmcs_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI Site Manager provisioned accounts';

-- -----------------------------------------------------------------------------
-- mod_aisitemanager_chat_history
-- Stores the full conversation history for each client.
-- Only user/assistant text messages are persisted here; tool-use exchanges
-- happen in-memory per request and are not stored.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_aisitemanager_chat_history` (
    `id`               INT(11)               NOT NULL AUTO_INCREMENT,
    `whmcs_client_id`  INT(11)               NOT NULL COMMENT 'References tblclients.id',
    `role`             ENUM('user','assistant') NOT NULL COMMENT 'Message author',
    `message`          TEXT                  NOT NULL COMMENT 'Message body (plain text or HTML)',
    `created_at`       DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client_id` (`whmcs_client_id`),
    KEY `idx_client_created` (`whmcs_client_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI Site Manager chat message history';

-- -----------------------------------------------------------------------------
-- mod_aisitemanager_settings
-- Global key/value settings for the module.
-- Default rows: api_key, header_wysiwyg_content.
-- Additional setting keys may be added in future versions.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_aisitemanager_settings` (
    `id`            INT(11)     NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(64) NOT NULL COMMENT 'Unique setting identifier',
    `setting_value` LONGTEXT    NOT NULL COMMENT 'Setting value (may be long HTML for WYSIWYG content)',
    `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI Site Manager global settings';

-- Default settings rows (INSERT IGNORE so re-running is safe)
INSERT IGNORE INTO `mod_aisitemanager_settings` (`setting_key`, `setting_value`) VALUES
    ('api_key', ''),
    ('header_wysiwyg_content', '<p>Welcome to <strong>AI Site Manager</strong>! Use the chat below to make changes to your website. Describe what you want in plain English — no technical knowledge needed.</p><p>All changes are saved to a <strong>staging area</strong> first. Click <strong>Commit</strong> when you are ready to publish. Click <strong>Discard</strong> to cancel all pending changes.</p>');
