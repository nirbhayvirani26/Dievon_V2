/* ============================================================
 *  Dievon Dialog — branded replacement for window.alert / confirm
 *
 *  Why: the native dialogs render as the OS chrome ("localhost:8888 says…"),
 *  which looks like a browser security warning rather than part of the shop.
 *
 *  Usage:
 *      dievonAlert('Saved successfully');                  // fire and forget
 *      dievonAlert('Could not save', { type: 'error' });
 *      if (await dievonConfirm('Delete this order?')) { … } // caller must be async
 *
 *  window.alert is overridden here, so the ~80 existing alert() calls across the
 *  site become branded with no call-site changes. window.confirm CANNOT be
 *  overridden the same way — the native one blocks and returns a boolean
 *  synchronously, which a custom modal cannot do — so confirm() call sites are
 *  converted to `await dievonConfirm(...)` individually.
 * ============================================================ */
(function () {
    'use strict';

    if (window.dievonAlert) { return; }   // already loaded

    var overlay = null;
    var previouslyFocused = null;

    var ICONS = {
        success: '✓',
        error:   '✕',
        warning: '!',
        confirm: '?',
        info:    'i'
    };
    var TITLES = {
        success: 'Success',
        error:   'Something Went Wrong',
        warning: 'Please Note',
        confirm: 'Please Confirm',
        info:    'Notice'
    };

    function build() {
        if (overlay) { return overlay; }
        overlay = document.createElement('div');
        overlay.className = 'dv-dialog-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="dv-dialog">' +
                '<div class="dv-dialog-icon" aria-hidden="true"></div>' +
                '<h2 class="dv-dialog-title"></h2>' +
                '<p class="dv-dialog-message"></p>' +
                '<div class="dv-dialog-actions"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    /* Long enough to outlast the CSS: the backdrop fades over 0.22s and the box
       finishes its transform at 0.26s (assets/css/style.css, .dv-dialog-overlay
       and .dv-dialog). Nothing that moves the page may happen before then. */
    var CLOSE_MS   = 300;
    var closeTimer = null;

    function close(result, resolve) {
        if (!overlay) { return; }
        var el = overlay;

        /* Close smoothly instead of snapping.
           ────────────────────────────────────────────────────────────────
           Opening looked right and closing flickered, because opening only
           ADDS a class while closing used to do three layout changes at the
           very moment the fade began — all of them visible for the whole
           0.26s the dialog was still on screen:

             1. dv-dialog-open came off the body, so overflow:hidden lifted
                and the scrollbar snapped back, shoving the page sideways.
             2. The prompt's input was pulled out of the DOM (see finish()),
                so the box lost the input's height and everything under it
                jumped up mid-fade.
             3. Focus jumped back to the rename button, which can scroll it
                into view — a second shift on top of the first.

           So the dialog faded out while the page moved underneath it. Now
           the class comes off immediately (the fade starts at once, which is
           what makes it feel responsive) and everything that changes layout
           waits until there is nothing left to see. */
        el.classList.remove('is-open');
        document.removeEventListener('keydown', el._keyHandler);

        if (closeTimer) { clearTimeout(closeTimer); }
        closeTimer = setTimeout(function () {
            closeTimer = null;
            // A dialog opened again during the fade: leave its state alone.
            if (el.classList.contains('is-open')) { return; }

            if (typeof el._cleanup === 'function') {
                try { el._cleanup(); } catch (e) {}
                el._cleanup = null;
            }
            document.body.classList.remove('dv-dialog-open');
            if (previouslyFocused && previouslyFocused.focus) {
                // preventScroll keeps the restore from yanking the page about;
                // ignored by older browsers, which then behave as before.
                try { previouslyFocused.focus({ preventScroll: true }); }
                catch (e) { try { previouslyFocused.focus(); } catch (e2) {} }
            }
        }, CLOSE_MS);

        // Resolved now, not in 300ms: the caller's save request should start
        // the moment the button is pressed, not after the animation.
        if (resolve) { resolve(result); }
    }

    /**
     * Settle a close that is still fading, before showing something new.
     *
     * The overlay is reused rather than rebuilt, so a dialog opened within the
     * 300ms would otherwise find the previous prompt's input still in the box
     * and add a second one beside it.
     */
    function flushPendingClose() {
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        if (overlay && typeof overlay._cleanup === 'function') {
            try { overlay._cleanup(); } catch (e) {}
            overlay._cleanup = null;
        }
    }

    /**
     * @param {string}  message
     * @param {object}  opts  { type, title, confirmText, cancelText, isConfirm, danger }
     * @returns {Promise<boolean>}
     */
    function open(message, opts) {
        opts = opts || {};
        var type = opts.type || (opts.isConfirm ? 'confirm' : 'info');
        var el = build();
        flushPendingClose();

        el.querySelector('.dv-dialog').className = 'dv-dialog dv-dialog--' + type;
        el.querySelector('.dv-dialog-icon').textContent = ICONS[type] || ICONS.info;
        el.querySelector('.dv-dialog-title').textContent = opts.title || TITLES[type] || TITLES.info;
        // textContent, never innerHTML — messages can contain user/server data.
        el.querySelector('.dv-dialog-message').textContent = String(message == null ? '' : message);

        var actions = el.querySelector('.dv-dialog-actions');
        actions.innerHTML = '';

        return new Promise(function (resolve) {
            if (opts.isConfirm) {
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'dv-dialog-btn dv-dialog-btn--ghost';
                cancel.textContent = opts.cancelText || 'Cancel';
                cancel.onclick = function () { close(false, resolve); };
                actions.appendChild(cancel);
            }

            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'dv-dialog-btn ' + (opts.danger ? 'dv-dialog-btn--danger' : 'dv-dialog-btn--primary');
            ok.textContent = opts.confirmText || (opts.isConfirm ? 'Confirm' : 'OK');
            ok.onclick = function () { close(true, resolve); };
            actions.appendChild(ok);

            /* Enter must do what the FOCUSED button says, and nothing else.
               ────────────────────────────────────────────────────────────────
               This read "Enter, unless the OK button is focused, confirms" —
               which is exactly backwards for anyone on a keyboard. Focus Cancel,
               press Enter, and activeElement is not `ok`, so the branch fired,
               preventDefault() suppressed Cancel's own native activation, and
               the dialog resolved TRUE. Pressing Enter on Cancel performed the
               destructive action.

               Driven live with buttons ["Cancel","Delete"]: Shift+Tab to Cancel,
               Enter, resolved true. This dialog guards permanent account
               deletion (pages/account.php:1209), address deletion, order
               cancellation and 58 admin call sites.

               No replacement branch is needed: a focused <button> already
               activates on Enter natively, and each button's own onclick
               resolves with the right answer. Escape stays. */
            el._keyHandler = function (e) {
                if (e.key === 'Escape') { close(false, resolve); }
                /* Tab must not leave an open modal.
                   ────────────────────────────────────────────────────────────
                   The overlay declares aria-modal="true", which tells a screen
                   reader the rest of the page is inert — but nothing enforced
                   it, so Tab walked straight out of the dialog into the header
                   and footer behind it. The user then landed on controls their
                   reader had just been told do not exist, with the dialog still
                   open and no visible focus anywhere on screen.
                   Cycling within the dialog is what aria-modal is promising. */
                if (e.key === 'Tab') {
                    var f = el.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                    if (!f.length) { return; }
                    var first = f[0], last = f[f.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault(); last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault(); first.focus();
                    }
                }
            };
            document.addEventListener('keydown', el._keyHandler);

            // Click the backdrop to dismiss — same as pressing Cancel.
            el.onclick = function (e) { if (e.target === el) { close(false, resolve); } };

            previouslyFocused = document.activeElement;
            document.body.classList.add('dv-dialog-open');
            el.classList.add('is-open');
            setTimeout(function () { ok.focus(); }, 60);
        });
    }

    /** Guess a sensible style from the wording of legacy alert() strings. */
    function inferType(message) {
        var m = String(message == null ? '' : message).toLowerCase();
        if (/(error|failed|could not|cannot|invalid|denied|wrong|unable|not allowed)/.test(m)) { return 'error'; }
        if (/(success|added|saved|updated|created|submitted|sent|complete|thank)/.test(m))     { return 'success'; }
        if (/(please|select|required|must|warning|notice)/.test(m))                            { return 'warning'; }
        return 'info';
    }

    window.dievonAlert = function (message, opts) {
        opts = opts || {};
        if (!opts.type) { opts.type = inferType(message); }
        return open(message, opts);
    };

    window.dievonConfirm = function (message, opts) {
        opts = opts || {};
        opts.isConfirm = true;
        if (!opts.type) { opts.type = opts.danger ? 'warning' : 'confirm'; }
        return open(message, opts);
    };

    /**
     * Ask for a line of text, in the shop's own dialog.
     *
     * The one shape this library was missing. Everything else had a styled
     * dialog — alert() is even replaced above — while anything needing an answer
     * typed in fell back to the browser's prompt(): a grey system box with the
     * URL printed in it, in the middle of a panel that otherwise looks like the
     * shop. Renaming a category was the visible case.
     *
     * Resolves with the trimmed string, or null if cancelled or left empty, so a
     * caller can treat "no answer" and "changed their mind" the same way.
     *
     * @param   {string} message
     * @param   {string} defaultValue
     * @param   {object} opts  { title, confirmText, cancelText, placeholder, maxlength }
     * @returns {Promise<string|null>}
     */
    window.dievonPrompt = function (message, defaultValue, opts) {
        opts = opts || {};
        var el = build();
        flushPendingClose();

        el.querySelector('.dv-dialog').className = 'dv-dialog dv-dialog--confirm';
        el.querySelector('.dv-dialog-icon').textContent = ICONS.confirm;
        el.querySelector('.dv-dialog-title').textContent = opts.title || 'Rename';
        el.querySelector('.dv-dialog-message').textContent = String(message == null ? '' : message);

        // Built fresh each time and removed on close, so a second call never
        // inherits the previous answer.
        var field = document.createElement('input');
        // opts.inputType lets a caller ask for a masked field. Added for the
        // admin's "set staff password" flow, which previously used window.prompt
        // and therefore showed the new password in plain text on screen.
        field.type = (opts && opts.inputType) || 'text';
        if (opts && opts.autocomplete) { field.autocomplete = opts.autocomplete; }
        field.className = 'dv-dialog-input';
        field.value = defaultValue == null ? '' : String(defaultValue);
        if (opts.placeholder) { field.placeholder = opts.placeholder; }
        field.maxLength = opts.maxlength || 120;

        var msgEl = el.querySelector('.dv-dialog-message');
        msgEl.parentNode.insertBefore(field, msgEl.nextSibling);

        /* Taken out once the dialog is off screen rather than on the way out,
           so the box keeps its shape while it fades. Registered on the overlay
           so every exit — button, Escape, click on the backdrop — drops the box
           the same way, and so a dialog opened later can flush it first. */
        el._cleanup = function () {
            if (field.parentNode) { field.parentNode.removeChild(field); }
        };

        var actions = el.querySelector('.dv-dialog-actions');
        actions.innerHTML = '';

        return new Promise(function (resolve) {
            function finish(value) {
                close(value, resolve);
            }

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'dv-dialog-btn dv-dialog-btn--ghost';
            cancel.textContent = opts.cancelText || 'Cancel';
            cancel.onclick = function () { finish(null); };
            actions.appendChild(cancel);

            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'dv-dialog-btn dv-dialog-btn--primary';
            ok.textContent = opts.confirmText || 'Save';
            ok.onclick = function () {
                var v = field.value.trim();
                finish(v === '' ? null : v);
            };
            actions.appendChild(ok);

            el._keyHandler = function (e) {
                if (e.key === 'Escape') { finish(null); }
                // Enter submits from the field — the usual expectation when a
                // dialog holds a single box.
                if (e.key === 'Enter' && document.activeElement === field) {
                    e.preventDefault();
                    ok.click();
                }
            };
            document.addEventListener('keydown', el._keyHandler);
            el.onclick = function (e) { if (e.target === el) { finish(null); } };

            previouslyFocused = document.activeElement;
            document.body.classList.add('dv-dialog-open');
            el.classList.add('is-open');
            // Focus the box and select what is in it, so typing replaces the old
            // name rather than appending to it.
            setTimeout(function () { field.focus(); field.select(); }, 60);
        });
    };

    // Drop-in replacement so existing alert() calls are branded automatically.
    // The native one is kept on window.nativeAlert in case it is ever needed.
    window.nativeAlert = window.alert;
    window.alert = function (message) { window.dievonAlert(message); };

    /* ── Helpers for inline handlers ──────────────────────────────────────
     * `onclick="return confirm(...)"` relies on confirm() blocking and returning
     * a boolean synchronously. A custom modal cannot do that, so instead these
     * always cancel the default action first, then re-trigger it for real once
     * the user has confirmed.
     *
     *   <a href="x.php?delete=1" onclick="return dvConfirmLink(this, 'Delete this?')">
     *   <form onsubmit="return dvConfirmForm(this, 'Delete this?')">
     */
    window.dvConfirmLink = function (el, message, opts) {
        opts = Object.assign({ danger: true, confirmText: 'Delete', cancelText: 'Cancel' }, opts || {});
        window.dievonConfirm(message, opts).then(function (ok) {
            // Navigate directly rather than calling el.click(), which would re-enter
            // this handler and loop.
            if (ok && el && el.href) { window.location.href = el.href; }
        });
        return false;
    };

    /* Which button was pressed, remembered for form.submit().
       ────────────────────────────────────────────────────────────────────────
       form.submit() submits with NO submitter, so the pressed button's own
       name/value never reaches the server. Almost every confirm on this site
       carries its payload that way — <button name="delete" value="37"> — and
       the handlers are written as isset($_POST['delete']). Through the dialog
       that key simply was not there: the handler did not run, the page reloaded
       unchanged, and the row was still sitting there with no error to explain
       it. Deleting the same row straight from SQL always worked, which is what
       finally placed the fault here rather than in the query or the data.

       Captured on the way down so it is recorded before any other click handler
       can stop the event, and re-attached as a hidden field at submit time.
       requestSubmit(button) would carry the submitter properly, but it re-fires
       onsubmit — which is this very function — so it cannot be used here. */
    var dvLastSubmitter = null;
    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest
            ? e.target.closest('button[type="submit"], input[type="submit"], button:not([type])')
            : null;
        if (el && el.form) { dvLastSubmitter = el; }
    }, true);

    function dvSubmitWithButton(form) {
        if (!form) { return; }
        var btn = dvLastSubmitter;
        if (btn && btn.form === form && btn.name &&
            !form.querySelector('input[data-dv-submitter]')) {
            var hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = btn.name;
            hidden.value = btn.value;
            hidden.setAttribute('data-dv-submitter', '');
            form.appendChild(hidden);
        }
        form.submit();
    }

    window.dvConfirmForm = function (form, message, opts) {
        opts = Object.assign({ danger: true, confirmText: 'Delete', cancelText: 'Cancel' }, opts || {});

        /* This returns false ALWAYS, and the form is submitted only from inside
           the dialog's callback. That is correct while the dialog works and
           merciless when it does not: if the panel never paints, or its promise
           never settles, the button does nothing at all — no submit, no error,
           no dialog. A Delete that silently does nothing reads as a broken
           delete, and the only response available is to press it again.

           So the custom dialog is now an enhancement rather than the mechanism.
           If it is missing, does not return a promise, or throws, the browser's
           own confirm() answers instead and TRUE is returned so the native
           submit proceeds. The destructive action still cannot happen without
           someone agreeing to it; it just can no longer be lost. */
        var pending;
        try {
            pending = (typeof window.dievonConfirm === 'function')
                ? window.dievonConfirm(message, opts)
                : null;
        } catch (err) { pending = null; }

        if (!pending || typeof pending.then !== 'function') {
            return window.confirm(message);   // true lets the form submit itself
        }

        pending.then(function (ok) {
            // HTMLFormElement.submit() deliberately does NOT fire onsubmit again,
            // so this cannot loop.
            if (ok && form) { dvSubmitWithButton(form); }
        }, function () {
            if (window.confirm(message) && form) { dvSubmitWithButton(form); }
        });
        return false;
    };
})();
