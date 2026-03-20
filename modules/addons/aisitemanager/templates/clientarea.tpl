{*
 * AI Site Manager — Client Area Smarty Template
 * WebJIVE · https://web-jive.com
 *
 * Renders the two-panel layout:
 *   Left  (32%) — slim header bar, uncommitted-changes banner, chat, input.
 *   Right (68%) — staging preview iframe, commit/discard action buttons.
 *
 * Template vars injected by aisitemanager_clientarea():
 *   not_available   bool    — Show "not available" message instead of the UI.
 *   staging_active  bool    — True if .ai_staging/ exists on disk.
 *   preview_url     string  — Iframe src URL (staged or live).
 *   site_url        string  — Customer's live site URL.
 *   ajax_url        string  — URL to ajax.php.
 *   asset_base      string  — Base URL for CSS/JS assets.
 *   nonce           string  — CSRF nonce for AJAX requests.
 *   header_content  string  — WYSIWYG HTML for the instructions header.
 *   chat_history    array   — Previous messages [{role, message, created_at}].
 *   client_id       int     — WHMCS client ID.
 *   preview_token   string  — Preview token (may be null if no staging).
 *}

{* ---- Module CSS ---- *}
<link rel="stylesheet" href="{$asset_base}/css/aisitemanager.css?v=14">

{if $not_available}
{* ====================== NOT AVAILABLE MESSAGE ====================== *}
<div class="aisitemanager-not-available">
    <div class="alert alert-info">
        <strong>AI Site Manager</strong> is not available for your account.
        Please contact support if you believe this is an error.
    </div>
</div>

{else}
{* ====================== MAIN TWO-PANEL LAYOUT ====================== *}

{* Data passed to JavaScript via hidden element — nothing sensitive here. *}
<div id="aisitemanager-data"
     data-ajax-url="{$ajax_url|escape:'html'}"
     data-nonce="{$nonce|escape:'html'}"
     data-staging-active="{if $staging_active}1{else}0{/if}"
     data-site-url="{$site_url|escape:'html'}"
     data-preview-url="{$preview_url|escape:'html'}"
     data-preview-base="{$preview_base|escape:'html'}"
     data-preview-token="{$preview_token|escape:'html'}"
     data-site-mode="{$site_mode|escape:'html'}"
     data-shareable-url="{$shareable_preview_url|escape:'html'}"
     style="display:none;"
     aria-hidden="true">
</div>

<div class="aisitemanager-wrapper">

    {* ================ LEFT PANEL — CHAT ================ *}
    <div class="aisitemanager-left" id="aisitemanager-left">

        {* ---- Slim header bar with help button ---- *}
        <div class="aisitemanager-header-bar">
            <span class="header-bar-title"><strong>AI Site Manager</strong></span>
            <button type="button"
                    class="aisitemanager-help-btn"
                    id="aisitemanager-help"
                    title="How to use AI Site Manager"
                    aria-label="Help">?</button>
        </div>

        {* ---- Uncommitted changes banner ---- *}
        <div class="aisitemanager-banner {if !$staging_active}aisitemanager-banner-hidden{/if}"
             id="aisitemanager-banner"
             role="alert">
            <span class="banner-text">
                ⚠️ You have uncommitted changes from your last session.
            </span>
            <div class="banner-actions">
                <button class="btn-commit-small" id="aisitemanager-banner-commit" type="button">Commit</button>
                <button class="btn-discard-small" id="aisitemanager-banner-discard" type="button">Discard</button>
            </div>
        </div>

        {* ---- Chat toolbar ---- *}
        <div class="aisitemanager-chat-toolbar">
            <button type="button" class="btn-clear-chat" id="aisitemanager-clear-chat"
                    title="Permanently delete all chat messages for this session">
                🗑 Clear Chat
            </button>
        </div>

        {* ---- Chat message history ---- *}
        <div class="aisitemanager-chat" id="aisitemanager-chat" role="log" aria-live="polite" aria-label="Chat history">

            {* Seed existing history from DB into the DOM *}
            {foreach $chat_history as $msg}
            <div class="aisitemanager-msg aisitemanager-msg-{$msg.role|escape:'html'}">
                <div class="msg-bubble" data-raw="{$msg.message|escape:'html'}">{$msg.message|nl2br}</div>
            </div>
            {/foreach}

            {* If no history, show a welcome nudge *}
            {if empty($chat_history)}
            <div class="aisitemanager-msg aisitemanager-msg-assistant aisitemanager-welcome">
                <div class="msg-bubble">
                    👋 Hi! I'm your AI web assistant. Tell me what you'd like to change on your website — I'll handle the technical side.
                </div>
            </div>
            {/if}

        </div>

        {* ---- Cancel bar (visible only while a request is in-flight) ---- *}
        <div class="aisitemanager-cancel-bar" id="aisitemanager-cancel-bar" style="display:none;">
            <button type="button" class="aisitemanager-cancel-btn" id="aisitemanager-cancel" aria-label="Cancel request">
                ⏹ Stop
            </button>
        </div>

        {* ---- Input area ---- *}
        <div class="aisitemanager-input-area">
            <div class="aisitemanager-op-status" id="aisitemanager-op-status" style="display:none;" aria-live="polite">
                <span class="spinner"></span>
                <span id="aisitemanager-op-text">Working on it…</span>
            </div>

            {* ---- Attachment chip area (hidden until a file is chosen) ---- *}
            <div class="aisitemanager-attachments" id="aisitemanager-attachments" style="display:none;"></div>

            {* Textarea — full width ---- *}
            <div class="aisitemanager-input-row">
                <textarea
                    id="aisitemanager-textarea"
                    class="aisitemanager-textarea"
                    placeholder="Tell me what you'd like to change on your website…"
                    rows="3"
                    aria-label="Chat input"
                    aria-describedby="aisitemanager-send-hint"
                ></textarea>
            </div>

            {* Actions row — attach on left, send on right ---- *}
            <div class="aisitemanager-input-actions">
                <div class="input-actions-left">
                    <input type="file"
                           id="aisitemanager-file-input"
                           class="aisitemanager-file-input"
                           accept="image/jpeg,image/png,image/gif,image/webp,.html,.css,.js,.txt,.xml"
                           style="display:none;"
                           aria-hidden="true">
                    <button type="button"
                            class="aisitemanager-attach-btn"
                            id="aisitemanager-attach"
                            title="Attach an image or text file (Enter to send · Shift+Enter for new line)"
                            aria-label="Attach file">📎</button>
                </div>
                <div class="input-actions-right">
                    <button
                        class="aisitemanager-send-btn"
                        id="aisitemanager-send"
                        type="button"
                        title="Send message (Enter)"
                        aria-label="Send message">
                        ➤
                    </button>
                </div>
            </div>
        </div>

    </div>{* .aisitemanager-left *}

    {* ================ RIGHT PANEL — PREVIEW ================ *}
    <div class="aisitemanager-right" id="aisitemanager-right">

        <div class="aisitemanager-preview-label">
            <span class="preview-label-text">Live Preview</span>

            <div class="preview-label-center">
                {* ---- Dev / Production pill toggle ---- *}
                <div class="aisitemanager-mode-toggle" id="aisitemanager-mode-toggle"
                     role="group" aria-label="Site mode">
                    <button type="button"
                            class="mode-pill{if $site_mode == 'construction'} mode-pill-active{/if}"
                            id="mode-pill-construction"
                            data-mode="construction"
                            title="Development mode — serve files direct from server, no live domain needed">
                        🏗 Development
                    </button>
                    <button type="button"
                            class="mode-pill{if $site_mode == 'production'} mode-pill-active{/if}"
                            id="mode-pill-production"
                            data-mode="production"
                            title="Production mode — preview against live domain, CSS and assets load perfectly">
                        🌐 Production
                    </button>
                </div>
            </div>

            <div class="preview-label-actions">
                {* ---- Copy shareable preview URL ---- *}
                <button type="button"
                        class="preview-btn-copy-url"
                        id="aisitemanager-copy-url"
                        title="Copy shareable preview link to clipboard"
                        {if !$shareable_preview_url}style="display:none;"{/if}>
                    🔗 Copy Link
                </button>

                <button type="button"
                        class="preview-btn-maximize"
                        id="aisitemanager-maximize"
                        title="Enter fullscreen — covers the full monitor">
                    <span class="maximize-icon-expand">⛶</span>
                    <span class="maximize-icon-restore" style="display:none;">⊡</span>
                    <span class="maximize-label">Fullscreen</span>
                </button>
                <a href="{$preview_url|escape:'html'}"
                   id="aisitemanager-preview-link"
                   target="_blank"
                   rel="noopener"
                   class="preview-open-link"
                   title="Open preview in a new tab">↗ New tab</a>
            </div>
        </div>

        <div class="aisitemanager-iframe-wrap" id="aisitemanager-iframe-wrap">
            <iframe
                id="aisitemanager-preview"
                class="aisitemanager-iframe"
                src="{$preview_url|escape:'html'}"
                title="Website preview"
            ></iframe>
        </div>

        <div class="aisitemanager-panel-actions">
            <button
                class="aisitemanager-commit-btn"
                id="aisitemanager-commit"
                type="button"
                title="Publish all staged changes to your live website."
                {if !$staging_active}disabled aria-disabled="true"{/if}>
                ✅ Commit Changes
            </button>
            <button
                class="aisitemanager-discard-btn"
                id="aisitemanager-discard"
                type="button"
                title="Permanently delete all staged changes."
                {if !$staging_active}disabled aria-disabled="true"{/if}>
                🗑 Discard
            </button>
        </div>

    </div>{* .aisitemanager-right *}

</div>{* .aisitemanager-wrapper *}

{* ---- Help modal — outside wrapper so it's not clipped by overflow:hidden ---- *}
<div class="aisitemanager-modal-overlay"
     id="aisitemanager-modal-overlay"
     aria-hidden="true"
     role="dialog"
     aria-modal="true"
     aria-labelledby="aisitemanager-modal-title">
    <div class="aisitemanager-modal">
        <div class="aisitemanager-modal-header">
            <h3 id="aisitemanager-modal-title">How to use AI Site Manager</h3>
            <button type="button"
                    class="aisitemanager-modal-close"
                    id="aisitemanager-modal-close"
                    aria-label="Close help">✕</button>
        </div>
        <div class="aisitemanager-modal-body">
            {$header_content}
        </div>
    </div>
</div>

{* ---- Module JavaScript ---- *}
<script src="{$asset_base}/js/aisitemanager.js?v=5"></script>

{/if}{* end not_available *}
