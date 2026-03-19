<?php
/**
 * AI Site Manager — English Language Strings
 * WebJIVE · https://web-jive.com
 *
 * These strings are available as $_ADDONLANG inside module functions
 * and as {$_lang.key} inside Smarty templates.
 */

$_ADDONLANG = [

    // Module meta
    'module_name'        => 'AI Site Manager',
    'module_description' => 'Edit your website using plain-language AI chat with live staging preview.',

    // Admin panel — general
    'admin_title'           => 'AI Site Manager Administration',
    'tab_accounts'          => 'Accounts',
    'tab_settings'          => 'Settings',
    'save_settings'         => 'Save Settings',
    'settings_saved'        => 'Settings saved successfully.',

    // Admin panel — stats
    'stat_total_accounts'   => 'Total Accounts',
    'stat_enabled_accounts' => 'AI Enabled',

    // Admin panel — accounts table
    'col_client'            => 'Client',
    'col_domain'            => 'Domain',
    'col_cpanel_user'       => 'cPanel User',
    'col_ai_status'         => 'AI Status',
    'col_staging'           => 'Staging',
    'col_actions'           => 'Actions',
    'status_enabled'        => 'Enabled',
    'status_disabled'       => 'Disabled',
    'status_active'         => 'Active',
    'status_none'           => '—',
    'btn_enable'            => 'Enable',
    'btn_disable'           => 'Disable',
    'btn_provision'         => 'Provision',
    'btn_deprovision'       => 'Deprovision',
    'no_accounts'           => 'No active hosting accounts found.',

    // Admin panel — settings
    'label_api_key'         => 'Anthropic API Key',
    'desc_api_key'          => 'Your Anthropic API key from console.anthropic.com. This value is stored encrypted in the database and never exposed to client browsers.',
    'label_header_content'  => 'Client Area Header Content',
    'desc_header_content'   => 'This HTML content appears at the top of the AI chat panel for all customers. Use it to provide instructions and links to tutorial videos. Supports text formatting and hyperlinks.',

    // Client area — general
    'page_title'            => 'AI Site Manager',
    'not_available'         => 'AI Site Manager is not available for your account. Please contact support.',

    // Client area — header
    'header_toggle_show'    => 'Show Instructions',
    'header_toggle_hide'    => 'Hide Instructions',

    // Client area — uncommitted changes banner
    'banner_uncommitted'    => 'You have uncommitted changes from your last session.',
    'btn_commit'            => 'Commit',
    'btn_discard'           => 'Discard',

    // Client area — chat
    'chat_placeholder'      => 'Tell me what you\'d like to change on your website…',
    'btn_send'              => 'Send',
    'chat_thinking'         => 'Working on it…',
    'chat_error'            => 'Something went wrong. Please try again.',

    // Client area — preview panel
    'preview_label'         => 'Live Preview',
    'btn_commit_changes'    => 'Commit Changes',
    'btn_discard_changes'   => 'Discard Changes',
    'btn_commit_tip'        => 'Publish all staged changes to your live website.',
    'btn_discard_tip'       => 'Permanently delete all staged changes.',

    // Confirm dialogs
    'confirm_commit'        => 'Are you sure you want to publish all staged changes to your live website? This cannot be undone.',
    'confirm_discard'       => 'Are you sure you want to discard all staged changes? They will be permanently deleted.',

    // Operation log messages shown in chat
    'op_reading'            => 'Reading',
    'op_writing'            => 'Writing changes to staging…',
    'op_listing'            => 'Browsing your website files…',
    'op_creating_dir'       => 'Creating directory in staging…',
    'op_deleting'           => 'Marking for deletion…',
    'op_committed'          => 'All changes have been published to your live site!',
    'op_discarded'          => 'All staged changes have been discarded.',

];
