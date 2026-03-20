/**
 * AI Site Manager — Chat UI
 * WebJIVE · https://web-jive.com
 *
 * Vanilla JavaScript. No external dependencies.
 *
 * Responsibilities:
 *   - Help modal (? button opens instructions overlay).
 *   - Send chat messages to ajax.php and render responses.
 *   - Show inline operation indicators ("Reading homepage…", "Writing changes…").
 *   - Refresh preview iframe after any file write.
 *   - Enable/disable Commit and Discard buttons based on staging state.
 *   - Handle Commit and Discard actions.
 *   - Show/hide the uncommitted-changes banner.
 *
 * All data (ajaxUrl, nonce, stagingActive, previewUrl, siteUrl) is read from
 * the #aisitemanager-data hidden element injected by the Smarty template.
 * Nothing sensitive (credentials, API key) passes through the browser at any
 * point — the ajax.php backend handles all secure operations.
 */

(function () {
    'use strict';

    // =========================================================================
    // DOM references
    // =========================================================================

    const dataEl      = document.getElementById('aisitemanager-data');
    const chatEl      = document.getElementById('aisitemanager-chat');
    const textareaEl  = document.getElementById('aisitemanager-textarea');
    const sendBtn     = document.getElementById('aisitemanager-send');
    const opStatus    = document.getElementById('aisitemanager-op-status');
    const opText      = document.getElementById('aisitemanager-op-text');
    const bannerEl    = document.getElementById('aisitemanager-banner');
    const commitBtn   = document.getElementById('aisitemanager-commit');
    const discardBtn  = document.getElementById('aisitemanager-discard');
    const bannerCommitBtn  = document.getElementById('aisitemanager-banner-commit');
    const bannerDiscardBtn = document.getElementById('aisitemanager-banner-discard');
    const helpBtn      = document.getElementById('aisitemanager-help');
    const modalOverlay = document.getElementById('aisitemanager-modal-overlay');
    const modalCloseBtn = document.getElementById('aisitemanager-modal-close');
    const previewFrame = document.getElementById('aisitemanager-preview');
    const maximizeBtn  = document.getElementById('aisitemanager-maximize');
    const previewLink  = document.getElementById('aisitemanager-preview-link');
    const wrapperEl    = document.querySelector('.aisitemanager-wrapper');
    const attachBtn    = document.getElementById('aisitemanager-attach');
    const fileInput    = document.getElementById('aisitemanager-file-input');
    const attachmentsEl = document.getElementById('aisitemanager-attachments');
    const iframeWrap   = document.getElementById('aisitemanager-iframe-wrap');
    const clearChatBtn = document.getElementById('aisitemanager-clear-chat');
    const cancelBarEl  = document.getElementById('aisitemanager-cancel-bar');
    const cancelBtn    = document.getElementById('aisitemanager-cancel');
    const modeToggleEl  = document.getElementById('aisitemanager-mode-toggle');
    const copyUrlBtn    = document.getElementById('aisitemanager-copy-url');

    // =========================================================================
    // Configuration from the data element
    // =========================================================================

    const cfg = {
        ajaxUrl:       dataEl ? dataEl.dataset.ajaxUrl      : '',
        nonce:         dataEl ? dataEl.dataset.nonce         : '',
        stagingActive: dataEl ? dataEl.dataset.stagingActive === '1' : false,
        siteUrl:       dataEl ? dataEl.dataset.siteUrl       : '',
        previewUrl:    dataEl ? dataEl.dataset.previewUrl    : '',
        // previewBase = tilde URL root (e.g. https://server/~user/).
        // Used to build staging token URLs and the live-site reload URL so that
        // the preview always shows files from the server directly, bypassing DNS.
        previewBase:   dataEl ? (dataEl.dataset.previewBase || dataEl.dataset.previewUrl || '') : '',
        previewToken:  dataEl ? dataEl.dataset.previewToken  : '',
        siteMode:      dataEl ? (dataEl.dataset.siteMode     || 'construction') : 'construction',
        shareableUrl:  dataEl ? (dataEl.dataset.shareableUrl || '')             : '',
    };

    // Mutable runtime state.
    let state = {
        stagingActive: cfg.stagingActive,
        previewToken:  cfg.previewToken,
        sending:       false,
        currentPath:   '',   // Relative path of the page currently shown in the preview iframe.
        siteMode:     cfg.siteMode,
        shareableUrl: cfg.shareableUrl,
    };

    // AbortController for the active fetch (null when idle).
    let activeAbort = null;

    // Currently pending file attachment: { file: File, objectUrl: string|null, isImage: bool }
    // Null when no file is attached.
    let attachedFile = null;

    // =========================================================================
    // Initialization
    // =========================================================================

    function init() {
        if (!dataEl) { return; } // Module not rendered (not_available state).

        // Render markdown in DB-seeded messages (data-raw holds the plain text).
        document.querySelectorAll('#aisitemanager-chat .msg-bubble[data-raw]').forEach(function (el) {
            el.innerHTML = renderMessageHtml(el.dataset.raw);
            el.removeAttribute('data-raw');
        });

        // Scroll chat to bottom on load.
        scrollChatToBottom();

        // Apply initial staging state to buttons and banner.
        syncStagingUI();

        // Bind events.
        if (sendBtn)         sendBtn.addEventListener('click',   handleSendClick);
        if (textareaEl)      textareaEl.addEventListener('keydown', handleTextareaKeydown);
        if (commitBtn)       commitBtn.addEventListener('click',  handleCommit);
        if (discardBtn)      discardBtn.addEventListener('click', handleDiscard);
        if (bannerCommitBtn)  bannerCommitBtn.addEventListener('click',  handleCommit);
        if (bannerDiscardBtn) bannerDiscardBtn.addEventListener('click', handleDiscard);
        if (helpBtn)         helpBtn.addEventListener('click', openHelpModal);
        if (modalCloseBtn)   modalCloseBtn.addEventListener('click', closeHelpModal);
        if (modalOverlay)    modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay) { closeHelpModal(); }
        });
        if (maximizeBtn)     maximizeBtn.addEventListener('click', handleMaximize);

        // File attachment — 📎 button opens the hidden file picker.
        if (attachBtn)  attachBtn.addEventListener('click', function () { if (fileInput) fileInput.click(); });
        if (fileInput)  fileInput.addEventListener('change', handleFileChange);

        // Clear chat history.
        if (clearChatBtn) clearChatBtn.addEventListener('click', handleClearChat);

        // Cancel in-flight request.
        if (cancelBtn) cancelBtn.addEventListener('click', handleCancelSend);

        if (modeToggleEl)  modeToggleEl.addEventListener('click', handleModeToggle);
        if (copyUrlBtn)    copyUrlBtn.addEventListener('click', handleCopyPreviewUrl);

        // Listen for navigation postMessages from the staging preview iframe.
        // ai_preview.php injects a script that fires these when the user clicks
        // internal links, so we can keep state.currentPath up to date.
        window.addEventListener('message', handleIframeMessage);

        // Sync button label when fullscreen state changes (including Esc-to-exit).
        document.addEventListener('fullscreenchange',       syncFullscreenUI);
        document.addEventListener('webkitfullscreenchange', syncFullscreenUI);

        // Responsive stacking: switch to vertical layout when the module
        // is narrower than 780px (handles WHMCS content columns on 13" laptops).
        if (wrapperEl && window.ResizeObserver) {
            new ResizeObserver(function (entries) {
                var w = entries[0].contentRect.width;
                wrapperEl.classList.toggle('aisitemanager-stacked', w < 780);
            }).observe(wrapperEl);
        }
    }

    // =========================================================================
    // Help modal
    // =========================================================================

    function openHelpModal() {
        if (!modalOverlay) { return; }
        modalOverlay.setAttribute('aria-hidden', 'false');
        // Close on Escape.
        document.addEventListener('keydown', handleModalEsc);
    }

    function closeHelpModal() {
        if (!modalOverlay) { return; }
        modalOverlay.setAttribute('aria-hidden', 'true');
        document.removeEventListener('keydown', handleModalEsc);
    }

    function handleModalEsc(e) {
        if (e.key === 'Escape') { closeHelpModal(); }
    }

    // =========================================================================
    // Clear chat history
    // =========================================================================

    function handleClearChat() {
        if (!confirm('Clear all chat messages? This cannot be undone.')) { return; }

        var body = new FormData();
        body.append('action', 'clearChat');
        body.append('nonce',  cfg.nonce);

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    // Wipe the chat DOM and show the welcome message.
                    if (chatEl) {
                        chatEl.innerHTML =
                            '<div class="aisitemanager-msg aisitemanager-msg-assistant aisitemanager-welcome">' +
                            '<div class="msg-bubble">👋 Hi! I\'m your AI web assistant. Tell me what you\'d like to change on your website — I\'ll handle the technical side.</div>' +
                            '</div>';
                    }
                }
            })
            .catch(function () {
                alert('Could not clear chat. Please try again.');
            });
    }

    // =========================================================================
    // Fullscreen — uses the browser Fullscreen API so the whole editor covers
    // the monitor the same way a fullscreen video does.  Esc always exits.
    // =========================================================================

    /** Enter or exit fullscreen on the wrapper element. */
    function handleMaximize() {
        if (!wrapperEl) { return; }
        if (getFullscreenElement()) {
            exitFullscreen();
        } else {
            enterFullscreen(wrapperEl);
        }
    }

    /** Cross-browser fullscreen request. Returns a Promise (or undefined). */
    function enterFullscreen(el) {
        if (el.requestFullscreen)       { return el.requestFullscreen(); }
        if (el.webkitRequestFullscreen) { return el.webkitRequestFullscreen(); }
    }

    /** Cross-browser fullscreen exit. */
    function exitFullscreen() {
        if (document.exitFullscreen)       { return document.exitFullscreen(); }
        if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
    }

    /** Returns the active fullscreen element (cross-browser). */
    function getFullscreenElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }

    /**
     * Sync the button icon + label whenever fullscreen state changes.
     * This also fires when the user presses Esc to exit.
     */
    function syncFullscreenUI() {
        if (!maximizeBtn) { return; }
        const isFs       = !!getFullscreenElement();
        const iconExpand  = maximizeBtn.querySelector('.maximize-icon-expand');
        const iconRestore = maximizeBtn.querySelector('.maximize-icon-restore');
        const label       = maximizeBtn.querySelector('.maximize-label');

        if (isFs) {
            if (iconExpand)  iconExpand.style.display  = 'none';
            if (iconRestore) iconRestore.style.display = '';
            if (label)       label.textContent         = 'Exit';
            maximizeBtn.title = 'Exit fullscreen (or press Esc)';
        } else {
            if (iconExpand)  iconExpand.style.display  = '';
            if (iconRestore) iconRestore.style.display = 'none';
            if (label)       label.textContent         = 'Fullscreen';
            maximizeBtn.title = 'Enter fullscreen — covers the full monitor';
        }
    }

    // =========================================================================
    // Send message
    // =========================================================================

    function handleTextareaKeydown(e) {
        // Enter without Shift = send; Shift+Enter = newline.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendClick();
        }
    }

    function handleSendClick() {
        if (state.sending) { return; }
        const message = textareaEl ? textareaEl.value.trim() : '';
        // Allow send if there is text OR an attached file.
        if (!message && !attachedFile) { return; }

        sendMessage(message);
    }

    async function sendMessage(message) {
        state.sending = true;
        setSendingUI(true);

        // Snapshot the attachment before clearing UI state.
        const currentAttachment = attachedFile;

        // Clear text input and attachment chip.
        if (textareaEl) { textareaEl.value = ''; }
        clearAttachment();

        // Render user message immediately (with attachment preview if any).
        appendMessage('user', message, [], currentAttachment);

        // Show typing indicator in chat; op-status is not needed alongside it.
        const typingEl = appendTypingIndicator();
        hideOpStatus();

        // Set up AbortController so the Cancel button can abort the fetch.
        activeAbort = new AbortController();

        try {
            const body = new FormData();
            body.append('action',       'chat');
            body.append('nonce',        cfg.nonce);
            body.append('message',      message);
            body.append('current_page', state.currentPath || '');

            // Append the file if one was selected.
            if (currentAttachment) {
                body.append('attachment', currentAttachment.file, currentAttachment.file.name);
            }

            const res = await fetch(cfg.ajaxUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                signal: activeAbort.signal,
            });

            // If server returned a non-200, try to read the JSON error body.
            if (!res.ok) {
                removeElement(typingEl);
                let errMsg = 'Server error (' + res.status + '). Please try again.';
                try {
                    const errData = await res.json();
                    if (errData && errData.error) { errMsg = errData.error; }
                } catch (_) { /* keep generic message */ }
                appendMessage('assistant', '⚠️ ' + errMsg, []);
                return;
            }

            // Guard against non-JSON response (e.g. PHP fatal error page).
            let data;
            try {
                data = await res.json();
            } catch (_) {
                removeElement(typingEl);
                appendMessage('assistant', '⚠️ Unexpected response from server. Please try again.', []);
                return;
            }

            if (data.error) {
                removeElement(typingEl);
                appendMessage('assistant', '⚠️ ' + data.error, []);
                return;
            }

            removeElement(typingEl);

            // Render Claude's response with any operation log.
            appendMessage('assistant', data.response, data.operations || []);

            // If staging was written, update preview and enable commit/discard.
            if (data.staging_written) {
                state.stagingActive = true;
                state.previewToken  = data.preview_token || state.previewToken;
                syncStagingUI();

                // Find the first write or edit operation to know which page changed.
                // Update currentPath so the next message includes the right context.
                var editedPath = '';
                if (data.operations) {
                    for (var i = 0; i < data.operations.length; i++) {
                        var op = data.operations[i];
                        if ((op.type === 'write' || op.type === 'edit') && op.path) {
                            editedPath = op.path.replace(/^\/+/, '');
                            break;
                        }
                    }
                }
                if (editedPath) {
                    state.currentPath = editedPath;
                }

                // Reload preview, pointing directly at the edited page.
                refreshPreview(state.previewToken, state.currentPath);
            }

        } catch (err) {
            removeElement(typingEl);
            if (err.name === 'AbortError') {
                appendMessage('assistant', '⏹ Request cancelled.');
            } else {
                appendMessage('assistant', '⚠️ Network error. Please check your connection and try again.');
            }
        } finally {
            activeAbort = null;
            state.sending = false;
            setSendingUI(false);
        }
    }

    // =========================================================================
    // Commit
    // =========================================================================

    async function handleCommit() {
        if (!state.stagingActive) { return; }
        if (!confirm('Are you sure you want to publish all staged changes to your live website? This cannot be undone.')) {
            return;
        }

        setSendingUI(true);
        showOpStatus('Publishing changes to your live site…');

        try {
            const body = new FormData();
            body.append('action', 'commit');
            body.append('nonce',  cfg.nonce);

            const res  = await fetch(cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
            const data = await res.json();

            if (!res.ok || data.error) {
                appendMessage('assistant', '⚠️ Commit failed: ' + (data.error || 'Unknown error.'));
                return;
            }

            // Staging is now cleared.
            state.stagingActive = false;
            // Keep previewToken — the token file lives outside .ai_staging/ and
            // survives the commit.  The server returns the same proxy URL so we
            // stay in the correct mode (production or construction) without
            // falling back to the raw tilde URL.
            if (data.preview_token) { state.previewToken = data.preview_token; }
            syncStagingUI();

            // Reload preview using the URL returned by the server (proxy URL),
            // falling back to tilde base only if the server gave us nothing.
            if (previewFrame && data.preview_url) {
                previewFrame.src = data.preview_url;
                setPreviewLinkHref(data.preview_url);
            } else {
                reloadPreviewToLive();
            }

            // Show confirmation in chat.
            appendMessage('assistant', '✅ All your changes have been published to your live website! Your visitors can now see the updates.');

        } catch (err) {
            appendMessage('assistant', '⚠️ Network error during commit. Please try again.');
        } finally {
            setSendingUI(false);
            hideOpStatus();
        }
    }

    // =========================================================================
    // Discard
    // =========================================================================

    async function handleDiscard() {
        if (!state.stagingActive) { return; }
        if (!confirm('Are you sure you want to discard all staged changes? They will be permanently deleted.')) {
            return;
        }

        setSendingUI(true);
        showOpStatus('Discarding all staged changes…');

        try {
            const body = new FormData();
            body.append('action', 'discard');
            body.append('nonce',  cfg.nonce);

            const res  = await fetch(cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
            const data = await res.json();

            if (!res.ok || data.error) {
                appendMessage('assistant', '⚠️ Discard failed: ' + (data.error || 'Unknown error.'));
                return;
            }

            state.stagingActive = false;
            if (data.preview_token) { state.previewToken = data.preview_token; }
            syncStagingUI();

            if (previewFrame && data.preview_url) {
                previewFrame.src = data.preview_url;
                setPreviewLinkHref(data.preview_url);
            } else {
                reloadPreviewToLive();
            }

            appendMessage('assistant', '🗑 All staged changes have been discarded. Your live website is unchanged.');

        } catch (err) {
            appendMessage('assistant', '⚠️ Network error during discard. Please try again.');
        } finally {
            setSendingUI(false);
            hideOpStatus();
        }
    }

    // =========================================================================
    // Mode toggle (Construction / Live)
    // =========================================================================

    function handleModeToggle(e) {
        var pill = e.target.closest('.mode-pill');
        if (!pill) return;
        var newMode = pill.dataset.mode;
        if (!newMode || newMode === state.siteMode) return;

        // Optimistically update pill appearance.
        document.querySelectorAll('.mode-pill').forEach(function (p) {
            p.classList.toggle('mode-pill-active', p.dataset.mode === newMode);
        });

        // Use the same AJAX pattern as the rest of the file.
        var formData = new FormData();
        formData.append('action', 'set_site_mode');
        formData.append('nonce',  cfg.nonce);
        formData.append('mode',   newMode);

        fetch(cfg.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    console.error('set_site_mode error:', data.error);
                    // Revert pill if server rejected it.
                    document.querySelectorAll('.mode-pill').forEach(function (p) {
                        p.classList.toggle('mode-pill-active', p.dataset.mode === state.siteMode);
                    });
                    return;
                }
                state.siteMode    = data.mode    || newMode;
                state.previewToken = data.preview_token || state.previewToken;
                state.shareableUrl = data.shareable_url || '';

                // Show/hide copy URL button.
                if (copyUrlBtn) {
                    copyUrlBtn.style.display = state.shareableUrl ? '' : 'none';
                }

                // Reload preview iframe with the new mode.
                if (previewFrame && data.preview_url) {
                    var path = state.currentPath || '';
                    var newSrc = path
                        ? data.preview_url + '&path=' + encodeURIComponent(path)
                        : data.preview_url;
                    previewFrame.src = newSrc;
                    if (previewLink) previewLink.href = newSrc;
                }
            })
            .catch(function (err) {
                console.error('set_site_mode fetch failed:', err);
            });
    }

    // =========================================================================
    // Copy preview URL
    // =========================================================================

    function handleCopyPreviewUrl() {
        var url = state.shareableUrl || cfg.shareableUrl;
        if (!url) return;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                var orig = copyUrlBtn.textContent;
                copyUrlBtn.textContent = '✅ Copied!';
                setTimeout(function () { copyUrlBtn.textContent = orig; }, 2000);
            }).catch(function () {
                prompt('Copy this preview URL:', url);
            });
        } else {
            // Fallback for HTTP or old browsers.
            prompt('Copy this preview URL:', url);
        }
    }

    // =========================================================================
    // UI helpers
    // =========================================================================

    /**
     * Append a message bubble to the chat.
     *
     * @param {string} role        'user' or 'assistant'.
     * @param {string} text        Message text (may contain newlines).
     * @param {Array}  operations  Optional array of file operation objects from backend.
     * @param {Object} [attachment] Optional attachment from attachedFile state (user messages only).
     * @returns {HTMLElement}      The appended message element.
     */
    function appendMessage(role, text, operations, attachment) {
        const msgEl = document.createElement('div');
        msgEl.className = 'aisitemanager-msg aisitemanager-msg-' + role;

        const bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        bubble.innerHTML = renderMessageHtml(text);

        // Render attachment preview inside the bubble (user messages only).
        if (attachment) {
            const attachDiv = document.createElement('div');
            attachDiv.className = 'msg-attachment';

            if (attachment.isImage && attachment.objectUrl) {
                // Show image thumbnail.
                const img = document.createElement('img');
                img.src = attachment.objectUrl;
                img.alt = escapeHtml(attachment.file.name);
                attachDiv.appendChild(img);
            } else if (attachment.file) {
                // Show file name badge.
                const badge = document.createElement('span');
                badge.className = 'msg-attach-file';
                badge.textContent = fileTypeIcon(attachment.file.name) + ' ' + attachment.file.name;
                attachDiv.appendChild(badge);
            }

            bubble.appendChild(attachDiv);
        }

        // Append operation log entries if present.
        if (operations && operations.length > 0) {
            const opsDiv = document.createElement('div');
            opsDiv.className = 'msg-ops';
            operations.forEach(function (op) {
                const entry = document.createElement('div');
                entry.className = 'op-entry';
                entry.innerHTML = '<span class="op-icon">' + opIcon(op.type) + '</span> '
                    + '<span>' + escapeHtml(opLabel(op)) + '</span>';
                opsDiv.appendChild(entry);
            });
            bubble.appendChild(opsDiv);
        }

        msgEl.appendChild(bubble);
        chatEl.appendChild(msgEl);
        scrollChatToBottom();
        return msgEl;
    }

    /** Append a typing indicator ("Working on it…") and return it. */
    function appendTypingIndicator() {
        const el = document.createElement('div');
        el.className = 'aisitemanager-msg aisitemanager-msg-assistant aisitemanager-typing';
        el.innerHTML = '<div class="msg-bubble"><span class="spinner"></span> <span style="color:#888;font-size:.85rem;">Working on it…</span></div>';
        chatEl.appendChild(el);
        scrollChatToBottom();
        return el;
    }

    function removeElement(el) {
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }

    function scrollChatToBottom() {
        if (chatEl) {
            chatEl.scrollTop = chatEl.scrollHeight;
        }
    }

    /** Enable or disable send button, textarea, and attach button while a request is in flight. */
    function setSendingUI(isSending) {
        if (sendBtn)    sendBtn.disabled    = isSending;
        if (textareaEl) textareaEl.disabled = isSending;
        if (attachBtn)  attachBtn.disabled  = isSending;
        // Show/hide the cancel bar.
        if (cancelBarEl) cancelBarEl.style.display = isSending ? 'flex' : 'none';
        if (!isSending) { hideOpStatus(); }
    }

    /** Cancel the active in-flight request. */
    function handleCancelSend() {
        if (activeAbort) { activeAbort.abort(); }
    }

    /**
     * Handle postMessage events from the staging preview iframe.
     * ai_preview.php fires { type: 'aisitemanager_navigate', path: 'about.html' }
     * whenever the user clicks an internal link in the preview.
     */
    function handleIframeMessage(e) {
        if (!e.data || e.data.type !== 'aisitemanager_navigate') { return; }
        var path = e.data.path || '';
        // Sanity: must be a string with no traversal.
        if (typeof path === 'string' && path.indexOf('..') === -1) {
            state.currentPath = path;
        }
    }

    function showOpStatus(text) {
        if (!opStatus) { return; }
        opStatus.style.display = 'flex';
        if (opText) { opText.textContent = text; }
    }

    function hideOpStatus() {
        if (opStatus) { opStatus.style.display = 'none'; }
    }

    /**
     * Synchronize the commit/discard buttons and banner to the current staging state.
     */
    function syncStagingUI() {
        const active = state.stagingActive;

        // Right-panel commit/discard buttons.
        if (commitBtn) {
            commitBtn.disabled = !active;
            commitBtn.setAttribute('aria-disabled', !active);
        }
        if (discardBtn) {
            discardBtn.disabled = !active;
            discardBtn.setAttribute('aria-disabled', !active);
        }

        // Uncommitted changes banner.
        if (bannerEl) {
            if (active) {
                bannerEl.classList.remove('aisitemanager-banner-hidden');
            } else {
                bannerEl.classList.add('aisitemanager-banner-hidden');
            }
        }
    }

    // =========================================================================
    // Preview iframe helpers
    // =========================================================================

    /**
     * Point the preview iframe at the staging shim URL and keep the new-tab
     * link in sync so clicking it always opens the same URL the iframe shows.
     *
     * We use previewBase (the tilde URL: https://server/~user/) so the preview
     * is always served directly from disk, bypassing any DNS pointing issues.
     *
     * @param {string} token  The preview token to include in the shim URL.
     * @param {string} [path] Optional relative path to show (e.g. 'about.html').
     *                        Omit or pass '' to show the home page.
     */
    function refreshPreview(token, path) {
        if (!previewFrame) { return; }
        if (!token) {
            reloadPreviewToLive();
            return;
        }

        // Build the staging preview URL from the tilde base (not the domain URL).
        const base = cfg.previewBase.replace(/\/+$/, '');
        let url = base + '/ai_preview.php?t=' + encodeURIComponent(token);
        if (path) {
            url += '&path=' + encodeURIComponent(path);
        }
        previewFrame.src = url;
        setPreviewLinkHref(url);
    }

    /** Point the preview iframe back to the live site (via tilde URL). */
    function reloadPreviewToLive() {
        if (!previewFrame) { return; }
        const url = cfg.previewBase || cfg.siteUrl || 'about:blank';
        previewFrame.src = url;
        setPreviewLinkHref(url);
    }

    /** Keep the "Open in new tab" link pointed at whatever the iframe is showing. */
    function setPreviewLinkHref(url) {
        if (previewLink) { previewLink.href = url; }
    }

    // =========================================================================
    // File attachment helpers
    // =========================================================================

    /**
     * Called when the user picks a file from the hidden file input.
     * Validates size client-side, creates an object URL for images, renders chip.
     */
    function handleFileChange() {
        if (!fileInput || !fileInput.files || !fileInput.files[0]) { return; }
        const file = fileInput.files[0];

        // Client-side guard: 5 MB max.
        if (file.size > 5 * 1024 * 1024) {
            alert('That file is too large (max 5 MB). Please choose a smaller file.');
            fileInput.value = '';
            return;
        }

        // Revoke any previous object URL to avoid memory leaks.
        if (attachedFile && attachedFile.objectUrl) {
            URL.revokeObjectURL(attachedFile.objectUrl);
        }

        const isImage  = file.type.startsWith('image/');
        const objectUrl = isImage ? URL.createObjectURL(file) : null;
        attachedFile   = { file, objectUrl, isImage };

        renderAttachmentChip();

        // Reset the input so the same file can be re-selected after removal.
        fileInput.value = '';
    }

    /** Render the attachment chip (thumbnail for images, icon+name for text files). */
    function renderAttachmentChip() {
        if (!attachmentsEl || !attachedFile) { return; }

        attachmentsEl.innerHTML = '';
        attachmentsEl.style.display = 'flex';

        const chip = document.createElement('div');
        chip.className = 'attachment-chip';

        if (attachedFile.isImage && attachedFile.objectUrl) {
            const img = document.createElement('img');
            img.src       = attachedFile.objectUrl;
            img.className = 'chip-thumb';
            img.alt       = '';
            chip.appendChild(img);
        } else {
            const icon = document.createElement('span');
            icon.className   = 'chip-icon';
            icon.textContent = fileTypeIcon(attachedFile.file.name);
            chip.appendChild(icon);
        }

        const nameEl = document.createElement('span');
        nameEl.className   = 'chip-name';
        nameEl.textContent = attachedFile.file.name;
        chip.appendChild(nameEl);

        const removeBtn = document.createElement('button');
        removeBtn.type      = 'button';
        removeBtn.className = 'chip-remove';
        removeBtn.textContent = '×';
        removeBtn.title = 'Remove attachment';
        removeBtn.addEventListener('click', clearAttachment);
        chip.appendChild(removeBtn);

        attachmentsEl.appendChild(chip);
    }

    /** Remove the current attachment chip and free the object URL. */
    function clearAttachment() {
        if (attachedFile && attachedFile.objectUrl) {
            URL.revokeObjectURL(attachedFile.objectUrl);
        }
        attachedFile = null;
        if (attachmentsEl) {
            attachmentsEl.innerHTML    = '';
            attachmentsEl.style.display = 'none';
        }
    }

    /**
     * Return an emoji icon for a filename based on its extension.
     * Used in chips and in chat bubble badges for non-image files.
     */
    function fileTypeIcon(filename) {
        const ext = (filename || '').split('.').pop().toLowerCase();
        const icons = { html: '🌐', htm: '🌐', css: '🎨', js: '⚙️', txt: '📝', xml: '📋' };
        return icons[ext] || '📄';
    }

    // =========================================================================
    // Operation display helpers
    // =========================================================================

    /** Map operation type to an emoji icon for display in the chat. */
    function opIcon(type) {
        const icons = {
            list:   '📂',
            read:   '📄',
            write:  '✏️',
            edit:   '✏️',
            mkdir:  '📁',
            delete: '🗑️',
        };
        return icons[type] || '🔧';
    }

    /** Build a human-readable label for a file operation. */
    function opLabel(op) {
        switch (op.type) {
            case 'list':   return 'Browsed files in ' + friendlyPath(op.path);
            case 'read':   return 'Read ' + friendlyPath(op.path);
            case 'write':  return 'Staged changes to ' + friendlyPath(op.path);
            case 'edit':   return 'Edited ' + friendlyPath(op.path);
            case 'mkdir':  return 'Created folder ' + friendlyPath(op.path);
            case 'delete': return 'Marked for deletion: ' + friendlyPath(op.path);
            default:       return op.type + ': ' + friendlyPath(op.path);
        }
    }

    /**
     * Return a customer-friendly version of a server path.
     * Strips leading slashes for display; hides technical path details.
     */
    function friendlyPath(path) {
        if (!path) { return 'your website'; }
        // Remove leading slash; show just the filename/directory.
        const clean = path.replace(/^\/+/, '');
        return clean || 'root directory';
    }

    // =========================================================================
    // Utility
    // =========================================================================

    /**
     * Convert a plain-text message (possibly with markdown) into safe HTML.
     *
     * Handles:
     *  - HTML entity escaping (XSS prevention).
     *  - **bold** → <strong>
     *  - *italic* → <em>  (only when not part of **)
     *  - Blank lines (two or more newlines) → paragraph break with a small gap.
     *  - Single newlines → <br> within a paragraph.
     *
     * @param  {string} text Raw message text.
     * @return {string}      Safe HTML string.
     */
    function renderMessageHtml(text) {
        if (typeof text !== 'string') { return ''; }

        // 1. Escape HTML entities first so we can safely inject tags below.
        let safe = text
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');

        // 2. Inline markdown: **bold** then *italic*.
        //    Bold first (so ** isn't treated as two bare *).
        safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');

        // 3. Split into paragraphs on two or more consecutive blank lines.
        //    Within each paragraph, turn remaining single newlines into <br>.
        const paragraphs = safe.split(/\n{2,}/);
        const htmlParagraphs = paragraphs.map(function (para) {
            return '<p>' + para.replace(/\n/g, '<br>') + '</p>';
        });

        return htmlParagraphs.join('');
    }

    function escapeHtml(str) {
        if (typeof str !== 'string') { return ''; }
        return str
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');
    }

    // =========================================================================
    // Boot
    // =========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
