/**
 * WiFiCare CDN — JavaScript antarmuka.
 *
 * Menangani: sidebar mobile, dropdown, modal konfirmasi, toast,
 * pencarian/filter tabel, pagination (pratinjau), upload foto,
 * rating bintang, validasi ringan, dan validasi form.
 *
 * Tidak ada pemanggilan API atau autentikasi pada tahap ini.
 */
(function () {
    'use strict';

    var WifiCare = {};

    /* ---------------------------------------------------------------------
     * Toast notification
     * ------------------------------------------------------------------- */

    var TOAST_META = {
        success: {
            title: 'Berhasil',
            duration: 4800,
            role: 'status',
            path: '<path d="m5 12.5 4.5 4.5L19 7.5"/>'
        },
        danger: {
            title: 'Gagal',
            duration: 10000,
            role: 'alert',
            path: '<path d="M6 6l12 12M18 6 6 18"/>'
        },
        warning: {
            title: 'Perhatian',
            duration: 7500,
            role: 'alert',
            path: '<path d="M12 4.5 20 19H4Z"/><path d="M12 10v4"/><path d="M12 16.8h.01"/>'
        },
        info: {
            title: 'Informasi',
            duration: 5600,
            role: 'status',
            path: '<circle cx="12" cy="12" r="8"/><path d="M12 11v5"/><path d="M12 8.2h.01"/>'
        }
    };

    var TOAST_LIMIT = 4;

    function normaliseToastType(type) {
        return Object.prototype.hasOwnProperty.call(TOAST_META, type) ? type : 'info';
    }

    /**
     * Tampilkan toast. Signature lama tetap didukung:
     *   WifiCare.toast('Pesan', 'success');
     * Opsi ketiga dapat berisi { title, duration }.
     */
    WifiCare.toast = function (message, type, options) {
        var stack = document.getElementById('toastStack');
        if (!stack || message === undefined || message === null || String(message).trim() === '') {
            return null;
        }

        options = options || {};
        type = normaliseToastType(type);
        var meta = TOAST_META[type];
        var duration = options.duration !== undefined ? Number(options.duration) : meta.duration;
        var title = options.title || meta.title;

        // Batasi jumlah toast agar layar tidak penuh saat terjadi beberapa event.
        while (stack.children.length >= TOAST_LIMIT) {
            stack.removeChild(stack.firstElementChild);
        }

        var toast = document.createElement('div');
        toast.className = 'app-toast app-toast--' + type;
        toast.setAttribute('role', meta.role);
        toast.setAttribute('aria-atomic', 'true');

        var icon = document.createElement('span');
        icon.className = 'app-toast-icon';
        icon.innerHTML = '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
            + 'focusable="false">' + meta.path + '</svg>';

        var content = document.createElement('div');
        content.className = 'app-toast-content';

        var titleNode = document.createElement('p');
        titleNode.className = 'app-toast-title';
        titleNode.textContent = title;

        var text = document.createElement('p');
        text.className = 'app-toast-text';
        text.textContent = String(message);

        content.appendChild(titleNode);
        content.appendChild(text);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'app-toast-close';
        close.setAttribute('aria-label', 'Tutup notifikasi');
        close.innerHTML = '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">'
            + '<path d="M6 6l12 12M18 6 6 18"/></svg>';
        close.addEventListener('click', function () {
            removeToast(toast);
        });

        toast.appendChild(icon);
        toast.appendChild(content);
        toast.appendChild(close);

        if (duration > 0) {
            var progress = document.createElement('span');
            progress.className = 'app-toast-progress';
            progress.setAttribute('aria-hidden', 'true');
            progress.style.animationDuration = duration + 'ms';
            toast.appendChild(progress);

            window.setTimeout(function () {
                removeToast(toast);
            }, duration);
        }

        stack.appendChild(toast);
        return toast;
    };

    WifiCare.alert = function (message, type, options) {
        return WifiCare.toast(message, type, options);
    };

    function removeToast(toast) {
        if (!toast || toast.classList.contains('is-leaving')) {
            return;
        }
        toast.classList.add('is-leaving');
        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 200);
    }

    var flashToastsInitialised = false;

    function initFlashToasts() {
        if (flashToastsInitialised) {
            return;
        }

        var node = document.getElementById('wificare-flash');
        if (!node) {
            return;
        }

        var messages;
        try {
            messages = JSON.parse(node.textContent || '[]');
        } catch (error) {
            return;
        }

        if (!Array.isArray(messages)) {
            return;
        }

        flashToastsInitialised = true;
        messages.forEach(function (item) {
            if (!item || !item.message) {
                return;
            }
            WifiCare.toast(String(item.message), item.type || 'info');
        });
    }

    WifiCare.initFlashToasts = initFlashToasts;

    /* ---------------------------------------------------------------------
     * Sidebar mobile
     * ------------------------------------------------------------------- */

    function initSidebar() {
        var sidebar = document.querySelector('[data-sidebar]');
        var toggle = document.querySelector('[data-menu-toggle]');
        var scrim = document.querySelector('.app-sidebar-scrim');
        var closeButtons = document.querySelectorAll('[data-menu-close]');

        if (!sidebar) {
            return;
        }

        function open() {
            sidebar.classList.add('is-open');
            if (scrim) {
                scrim.hidden = false;
                window.requestAnimationFrame(function () {
                    scrim.classList.add('is-visible');
                });
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }
        }

        function close() {
            sidebar.classList.remove('is-open');
            if (scrim) {
                scrim.classList.remove('is-visible');
                window.setTimeout(function () {
                    scrim.hidden = true;
                }, 200);
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (sidebar.classList.contains('is-open')) {
                    close();
                } else {
                    open();
                }
            });
        }

        closeButtons.forEach(function (button) {
            button.addEventListener('click', close);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Submenu sidebar (expand/collapse)
     * ------------------------------------------------------------------- */

    function initSidebarSubmenus() {
        var toggles = document.querySelectorAll('[data-submenu-toggle]');

        toggles.forEach(function (toggle) {
            var panelId = toggle.getAttribute('aria-controls');
            var panel = panelId ? document.getElementById(panelId) : null;
            if (!panel) {
                return;
            }

            toggle.addEventListener('click', function () {
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                panel.hidden = expanded;
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Kompatibilitas hash lama WhatsApp (#otp, #notifikasi, #laporan, #audit)
     * ------------------------------------------------------------------- */

    function initLegacyWhatsAppHash() {
        var suffix = '/admin/whatsapp/gateway';
        var path = window.location.pathname.replace(/\/+$/, '');
        var hash = (window.location.hash || '').replace(/^#/, '').toLowerCase();
        var map = {
            otp: '/admin/whatsapp/otp',
            notifikasi: '/admin/whatsapp/notifikasi',
            laporan: '/admin/whatsapp/laporan',
            audit: '/admin/whatsapp/audit'
        };

        if (!map[hash] || path.slice(-suffix.length) !== suffix) {
            return;
        }

        window.location.replace(path.slice(0, -suffix.length) + map[hash]);
    }

    /* ---------------------------------------------------------------------
     * Dropdown header
     * ------------------------------------------------------------------- */

    function initDropdowns() {
        var toggles = document.querySelectorAll('[data-dropdown-toggle]');

        function closeAll(except) {
            document.querySelectorAll('[data-dropdown-target]').forEach(function (toggle) {
                var target = document.getElementById(toggle.getAttribute('data-dropdown-target'));
                if (target && target !== except) {
                    target.hidden = true;
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        toggles.forEach(function (toggle) {
            var target = document.getElementById(toggle.getAttribute('data-dropdown-target'));
            if (!target) {
                return;
            }

            toggle.setAttribute('aria-expanded', 'false');
            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                var willOpen = target.hidden;
                closeAll(target);
                target.hidden = !willOpen;
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.dropdown')) {
                closeAll(null);
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Modal reusable, focus trap, dan konfirmasi asynchronous
     * ------------------------------------------------------------------- */

    var activeModal = null;
    var activeModalOpener = null;
    var confirmState = null;

    var MODAL_ICONS = {
        check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        x: '<path d="M6 6l12 12M18 6 6 18"/>',
        alert: '<path d="M12 4.5 20 19H4Z"/><path d="M12 10v4"/><path d="M12 16.8h.01"/>',
        info: '<circle cx="12" cy="12" r="8"/><path d="M12 11v5"/><path d="M12 8.2h.01"/>',
        upload: '<path d="M12 16V5"/><path d="m7.5 9.5 4.5-4.5 4.5 4.5"/><path d="M5 19h14"/>',
        tool: '<path d="M14.7 6.3a4 4 0 0 1 5.3 5.3L11 20.6a2.8 2.8 0 0 1-4-4Z"/><path d="M14.7 6.3 9.5 11.5"/><path d="M17.5 4.5a4 4 0 0 0 2 3"/>',
        lock: '<rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/>',
        trash: '<path d="M5 7h14"/><path d="M9 7V5h6v2"/><path d="M7 7l1 12h8l1-12"/>',
        star: '<path d="m12 4 2.4 5 5.6.6-4.2 3.8 1.2 5.6L12 16.4 7 19l1.2-5.6L4 9.6l5.6-.6Z"/>',
        bell: '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 13 6 9Z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
        settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.6 7.6 0 0 0 .1-2l1.7-1.3-1.8-3.1-2 .8a7.7 7.7 0 0 0-1.7-1L15.4 4h-3.6l-.3 2.4a7.7 7.7 0 0 0-1.7 1l-2-.8L6 9.7 7.7 11a7.6 7.6 0 0 0 0 2L6 14.3l1.8 3.1 2-.8a7.7 7.7 0 0 0 1.7 1l.3 2.4h3.6l.3-2.4a7.7 7.7 0 0 0 1.7-1l2 .8 1.8-3.1Z"/>'
    };

    var MODAL_VARIANTS = ['primary', 'danger', 'success', 'warning', 'info'];

    function modalIconSvg(name) {
        var path = MODAL_ICONS[name] || MODAL_ICONS.info;
        return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
            + 'focusable="false">' + path + '</svg>';
    }

    function modalFocusable(modal) {
        return Array.prototype.slice.call(modal.querySelectorAll(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), '
            + 'select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(function (element) {
            return element.getClientRects().length > 0;
        });
    }

    function ensureModalA11y(modal) {
        var dialog = modal.querySelector('.app-modal');
        if (!dialog) {
            return null;
        }

        var suffix = String(modal.getAttribute('data-modal') || 'dialog')
            .replace(/[^a-z0-9_-]+/gi, '-')
            .toLowerCase();

        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('tabindex', '-1');

        var title = dialog.querySelector('.app-modal-title');
        if (title) {
            if (!title.id) {
                title.id = 'app-modal-title-' + suffix;
            }
            dialog.setAttribute('aria-labelledby', title.id);
        }

        var description = dialog.querySelector('.app-modal-message, [data-modal-description]');
        if (description) {
            if (!description.id) {
                description.id = 'app-modal-desc-' + suffix;
            }
            dialog.setAttribute('aria-describedby', description.id);
        }

        return dialog;
    }

    function cancelPendingConfirm(modal) {
        if (confirmState && confirmState.modal === modal) {
            var state = confirmState;
            confirmState = null;
            state.resolve(false);
        }
    }

    function openModal(id, opener) {
        var modal = document.querySelector('[data-modal="' + id + '"]');
        if (!modal) {
            return null;
        }

        if (activeModal && activeModal !== modal) {
            hideModal(activeModal, false);
        }

        if (modal.__wcCloseTimer) {
            window.clearTimeout(modal.__wcCloseTimer);
            modal.__wcCloseTimer = null;
        }

        modal.hidden = false;
        modal.classList.remove('is-closing');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');

        var dialog = ensureModalA11y(modal);
        activeModal = modal;
        activeModalOpener = opener || document.activeElement;
        document.body.classList.add('app-modal-open');

        window.setTimeout(function () {
            if (!dialog) {
                return;
            }
            var target = dialog.querySelector('[data-modal-autofocus], input:not([type="hidden"]), select, textarea');
            (target || dialog).focus();
        }, 30);

        return modal;
    }

    function hideModal(modal, restoreFocus) {
        if (!modal || modal.hidden || modal.classList.contains('is-closing')) {
            return;
        }

        cancelPendingConfirm(modal);
        modal.classList.remove('is-open');
        modal.classList.add('is-closing');

        var opener = activeModalOpener;
        if (activeModal === modal) {
            activeModal = null;
            activeModalOpener = null;
        }

        if (modal.__wcCloseTimer) {
            window.clearTimeout(modal.__wcCloseTimer);
        }

        modal.__wcCloseTimer = window.setTimeout(function () {
            modal.hidden = true;
            modal.classList.remove('is-closing');
            modal.setAttribute('aria-hidden', 'true');
            modal.__wcCloseTimer = null;

            var busyButton = modal.querySelector('[data-modal-confirm]');
            if (busyButton) {
                busyButton.classList.remove('is-loading');
                busyButton.disabled = false;
                busyButton.removeAttribute('aria-busy');
            }

            if (!document.querySelector('.app-modal-backdrop:not([hidden])')) {
                document.body.classList.remove('app-modal-open');
            }

            if (restoreFocus !== false && opener && typeof opener.focus === 'function' && opener.isConnected) {
                opener.focus();
            }
        }, 170);
    }

    function setButtonBusy(button, label) {
        if (!button) {
            return;
        }
        button.classList.add('is-loading');
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        if (label) {
            button.setAttribute('aria-label', label);
        }
    }

    function resetButtonBusy(button) {
        if (!button) {
            return;
        }
        button.classList.remove('is-loading');
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.removeAttribute('aria-label');
    }

    function readConfirmOptions(element) {
        return {
            title: element.getAttribute('data-confirm-title') || 'Konfirmasi tindakan',
            message: element.getAttribute('data-confirm-message')
                || 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
            confirmLabel: element.getAttribute('data-confirm-label') || 'Ya, lanjutkan',
            cancelLabel: element.getAttribute('data-confirm-cancel') || 'Batal',
            variant: element.getAttribute('data-confirm-variant') || 'primary',
            icon: element.getAttribute('data-confirm-icon') || 'alert',
            loadingLabel: element.getAttribute('data-confirm-loading') || 'Memproses...'
        };
    }

    /**
     * Dialog konfirmasi asynchronous. Mengembalikan Promise<boolean>.
     * Tidak memakai window.confirm bawaan browser.
     */
    WifiCare.confirm = function (options) {
        options = options || {};

        return new Promise(function (resolve) {
            var modal = document.querySelector('[data-modal="appConfirmModal"]');
            if (!modal) {
                WifiCare.toast('Dialog konfirmasi tidak tersedia pada halaman ini.', 'danger');
                resolve(false);
                return;
            }

            if (confirmState) {
                var previous = confirmState;
                confirmState = null;
                previous.resolve(false);
            }

            var variant = MODAL_VARIANTS.indexOf(options.variant) !== -1 ? options.variant : 'primary';
            var dialog = ensureModalA11y(modal);
            var title = modal.querySelector('.app-modal-title');
            var message = modal.querySelector('.app-modal-message');
            var iconBox = modal.querySelector('[data-modal-icon]');
            var confirmButton = modal.querySelector('[data-modal-confirm]');
            var confirmLabel = modal.querySelector('[data-modal-confirm-label]');
            var cancelLabel = modal.querySelector('[data-modal-cancel-label]');

            if (dialog) {
                dialog.className = 'app-modal app-modal--confirm app-modal--' + variant;
            }
            if (title) {
                title.textContent = options.title || 'Konfirmasi tindakan';
            }
            if (message) {
                message.textContent = options.message || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
            }
            if (iconBox) {
                iconBox.className = 'app-modal-icon app-modal-icon--' + variant;
                iconBox.innerHTML = modalIconSvg(options.icon || 'alert');
            }
            if (confirmLabel) {
                confirmLabel.textContent = options.confirmLabel || 'Ya, lanjutkan';
            }
            if (cancelLabel) {
                cancelLabel.textContent = options.cancelLabel || 'Batal';
            }
            if (confirmButton) {
                confirmButton.className = 'btn btn-' + variant + ' btn-sm';
                resetButtonBusy(confirmButton);
            }

            confirmState = {
                modal: modal,
                resolve: resolve,
                button: confirmButton,
                loadingLabel: options.loadingLabel || 'Memproses...',
                keepOpen: options.keepOpen === true
            };

            openModal('appConfirmModal', options.opener || document.activeElement);
        });
    };

    function resolveConfirm(confirmed) {
        if (!confirmState) {
            return;
        }

        var state = confirmState;
        confirmState = null;

        if (!confirmed) {
            hideModal(state.modal, true);
            state.resolve(false);
            return;
        }

        setButtonBusy(state.button, state.loadingLabel);
        state.resolve(true);

        if (!state.keepOpen) {
            window.setTimeout(function () {
                hideModal(state.modal, true);
            }, 120);
        }
    }

    function submitModalForm(button) {
        var formId = button.getAttribute('data-modal-submit');
        var form = formId ? document.getElementById(formId) : null;
        if (!form) {
            WifiCare.toast('Formulir tidak ditemukan. Muat ulang halaman lalu coba lagi.', 'danger');
            return;
        }

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        setButtonBusy(button, 'Memproses...');
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function submitConfirmedForm(form, submitter) {
        form.setAttribute('data-confirm-bypass', '1');
        try {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter || undefined);
            } else {
                form.submit();
            }
        } catch (error) {
            form.removeAttribute('data-confirm-bypass');
            WifiCare.toast('Formulir tidak dapat dikirim. Silakan coba lagi.', 'danger');
        }
    }

    function initModals() {
        document.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-modal-open]');
            if (opener) {
                event.preventDefault();
                openModal(opener.getAttribute('data-modal-open'), opener);
                return;
            }

            var closeButton = event.target.closest('[data-modal-close]');
            if (closeButton) {
                event.preventDefault();
                hideModal(closeButton.closest('.app-modal-backdrop'), true);
                return;
            }

            if (event.target.classList && event.target.classList.contains('app-modal-backdrop')) {
                hideModal(event.target, true);
                return;
            }

            // Tombol pada modal khusus halaman (mis. simpan pengaturan AI,
            // tandai semua notifikasi) mengirim formulir yang ditunjuk.
            var submitButton = event.target.closest('[data-modal-submit]');
            if (submitButton) {
                event.preventDefault();
                submitModalForm(submitButton);
                return;
            }

            var confirmButton = event.target.closest('[data-modal-confirm]');
            if (confirmButton && confirmButton.closest('[data-modal="appConfirmModal"]')) {
                event.preventDefault();
                resolveConfirm(true);
                return;
            }

            if (confirmButton) {
                var modal = confirmButton.closest('.app-modal-backdrop');
                var toastMessage = confirmButton.getAttribute('data-toast-message');
                hideModal(modal, true);
                if (toastMessage) {
                    WifiCare.toast(toastMessage, 'success');
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!activeModal) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                hideModal(activeModal, true);
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            var dialog = activeModal.querySelector('.app-modal');
            var focusable = modalFocusable(activeModal);
            if (focusable.length === 0) {
                event.preventDefault();
                if (dialog) {
                    dialog.focus();
                }
                return;
            }

            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            var current = document.activeElement;

            if (event.shiftKey && (current === first || current === dialog || !activeModal.contains(current))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (current === last || current === dialog || !activeModal.contains(current))) {
                event.preventDefault();
                first.focus();
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Konfirmasi generik (data-confirm): form, tautan, dan tombol
     * ------------------------------------------------------------------- */

    function initConfirm() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM') {
                return;
            }

            // Submit kedua setelah pengguna menekan tombol konfirmasi.
            if (form.getAttribute('data-confirm-bypass') === '1') {
                form.removeAttribute('data-confirm-bypass');
                return;
            }

            if (!form.hasAttribute('data-confirm') || event.defaultPrevented) {
                return;
            }

            if (form.getAttribute('data-submitting') === '1') {
                event.preventDefault();
                return;
            }

            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            event.preventDefault();
            var submitter = event.submitter || null;
            var options = readConfirmOptions(form);
            options.keepOpen = true;
            options.opener = submitter || form;

            WifiCare.confirm(options).then(function (confirmed) {
                if (confirmed) {
                    submitConfirmedForm(form, submitter);
                }
            });
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-confirm]:not(form)');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            var options = readConfirmOptions(trigger);

            WifiCare.confirm(options).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                var formId = trigger.getAttribute('data-confirm-form');
                var form = formId ? document.getElementById(formId) : (trigger.form || null);
                var href = trigger.getAttribute('data-confirm-href')
                    || (trigger.tagName === 'A' ? trigger.href : '');

                if (form) {
                    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(trigger.tagName === 'BUTTON' ? trigger : undefined);
                    } else {
                        form.submit();
                    }
                    return;
                }

                if (href) {
                    if (trigger.getAttribute('target') === '_blank') {
                        window.open(href, '_blank', 'noopener');
                    } else {
                        window.location.assign(href);
                    }
                    return;
                }

                var toastMessage = trigger.getAttribute('data-confirm-toast');
                if (toastMessage) {
                    WifiCare.toast(toastMessage, 'success');
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Pesan toast melalui atribut data-toast-message
     * ------------------------------------------------------------------- */

    function initToastTriggers() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-toast-message]');
            if (!trigger || trigger.hasAttribute('data-modal-confirm') || trigger.hasAttribute('data-confirm')) {
                return;
            }
            var type = trigger.getAttribute('data-toast-type') || 'info';
            WifiCare.toast(trigger.getAttribute('data-toast-message'), type);
        });
    }

    /* ---------------------------------------------------------------------
     * Pencarian & filter tabel (sisi klien)
     * ------------------------------------------------------------------- */

    function initTables() {
        document.querySelectorAll('[data-table-search]').forEach(function (input) {
            input.addEventListener('input', function () {
                filterTable(input.getAttribute('data-table-search'), input.value, null);
            });
        });

        document.querySelectorAll('[data-table-filter]').forEach(function (select) {
            select.addEventListener('change', function () {
                var tableId = select.getAttribute('data-table-filter');
                var search = document.querySelector('[data-table-search="' + tableId + '"]');
                filterTable(tableId, search ? search.value : '', null);
            });
        });
    }

    function filterTable(tableId, term, _unused) {
        var table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        term = (term || '').toLowerCase().trim();
        var rows = table.querySelectorAll('tbody tr');
        var visible = 0;

        rows.forEach(function (row) {
            if (row.classList.contains('table-empty-row')) {
                return;
            }
            var text = row.textContent.toLowerCase();
            var match = term === '' || text.indexOf(term) !== -1;
            row.classList.toggle('is-hidden', !match);
            if (match) {
                visible++;
            }
        });

        var isEmptyTable = table.querySelector('.table-empty-row') !== null;
        var card = table.closest('.table-card');
        var notice = card ? card.querySelector('[data-table-empty]') : null;
        if (notice) {
            notice.hidden = !(visible === 0 && !isEmptyTable);
        }
    }

    /* ---------------------------------------------------------------------
     * Pagination (pratinjau statis)
     * ------------------------------------------------------------------- */

    function initPagination() {
        document.querySelectorAll('[data-pagination]').forEach(function (nav) {
            nav.addEventListener('click', function (event) {
                // Hanya tombol pratinjau (tanpa URL) yang ditangani skrip.
                // Tautan server-side (mode nyata) dibiarkan bernavigasi normal.
                var button = event.target.closest('button[data-page]');
                if (!button || button.disabled) {
                    return;
                }
                nav.querySelectorAll('button[data-page]').forEach(function (item) {
                    item.classList.remove('is-active');
                    item.removeAttribute('aria-current');
                });
                button.classList.add('is-active');
                button.setAttribute('aria-current', 'page');
                WifiCare.toast('Navigasi halaman bersifat pratinjau sampai data terhubung.', 'info');
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Upload foto
     * ------------------------------------------------------------------- */

    function initUploads() {
        document.querySelectorAll('[data-upload]').forEach(function (zone) {
            var input = zone.querySelector('[data-upload-input]');
            var preview = zone.parentElement.querySelector('[data-upload-preview]');
            var error = zone.parentElement.querySelector('[data-upload-error]');
            if (!input) {
                return;
            }

            var maxSize = 5 * 1024 * 1024;
            var allowed = ['image/jpeg', 'image/png', 'image/webp'];

            function showError(message) {
                if (!error) {
                    return;
                }
                error.textContent = message;
                error.hidden = message === '';
                error.classList.toggle('is-error', message !== '');
            }

            function formatSize(bytes) {
                if (bytes >= 1048576) {
                    return (bytes / 1048576).toFixed(1) + ' MB';
                }
                return Math.max(1, Math.round(bytes / 1024)) + ' KB';
            }

            function render() {
                if (!preview) {
                    return;
                }
                preview.innerHTML = '';
                showError('');

                Array.prototype.forEach.call(input.files, function (file) {
                    if (allowed.indexOf(file.type) === -1) {
                        showError('Format berkas tidak didukung: ' + file.name + '. Gunakan JPG, PNG, atau WebP.');
                        return;
                    }
                    if (file.size > maxSize) {
                        showError('Ukuran berkas melebihi 5 MB: ' + file.name + '.');
                        return;
                    }

                    var item = document.createElement('li');
                    item.className = 'upload-preview-item';

                    var img = document.createElement('img');
                    img.alt = file.name;
                    img.src = URL.createObjectURL(file);
                    img.onload = function () {
                        URL.revokeObjectURL(img.src);
                    };

                    var meta = document.createElement('span');
                    meta.className = 'upload-preview-meta';
                    meta.textContent = file.name + ' (' + formatSize(file.size) + ')';

                    item.appendChild(img);
                    item.appendChild(meta);
                    preview.appendChild(item);
                });
            }

            input.addEventListener('change', render);

            // Cegah pengiriman bila foto wajib belum dipilih atau berkas invalid,
            // sekaligus mencegah pengiriman ganda.
            var form = zone.closest('form');
            if (form) {
                form.addEventListener('submit', function (event) {
                    if (form.getAttribute('data-submitting') === '1') {
                        event.preventDefault();
                        return;
                    }
                    if (input.files.length === 0) {
                        event.preventDefault();
                        showError('Foto bukti gangguan wajib diunggah.');
                        return;
                    }
                    if (error && error.textContent !== '') {
                        event.preventDefault();
                        return;
                    }

                    // Saat form memakai modal konfirmasi, submit pertama hanya
                    // membuka modal. Penanda double-submit dipasang pada submit
                    // kedua setelah pengguna mengonfirmasi.
                    if (form.hasAttribute('data-confirm') && form.getAttribute('data-confirm-bypass') !== '1') {
                        return;
                    }

                    form.setAttribute('data-submitting', '1');
                });
            }

            ['dragenter', 'dragover'].forEach(function (type) {
                zone.addEventListener(type, function (event) {
                    event.preventDefault();
                    zone.classList.add('is-dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function (type) {
                zone.addEventListener(type, function (event) {
                    event.preventDefault();
                    zone.classList.remove('is-dragover');
                });
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Rating bintang
     * ------------------------------------------------------------------- */

    function initRating() {
        document.querySelectorAll('[data-rating]').forEach(function (group) {
            var stars = group.querySelectorAll('[data-rating-value]');
            var text = group.parentElement.querySelector('[data-rating-text]');

            stars.forEach(function (star) {
                star.addEventListener('click', function () {
                    var value = parseInt(star.getAttribute('data-rating-value'), 10) || 0;
                    group.setAttribute('data-value', String(value));
                    stars.forEach(function (item) {
                        var itemValue = parseInt(item.getAttribute('data-rating-value'), 10) || 0;
                        item.classList.toggle('is-active', itemValue <= value);
                        item.setAttribute('aria-checked', itemValue === value ? 'true' : 'false');
                    });
                    var hidden = group.querySelector('input[type="hidden"]');
                    if (hidden) {
                        hidden.value = String(value);
                    }
                    if (text) {
                        text.textContent = 'Anda memilih ' + value + ' dari 5 bintang.';
                    }
                });
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Chat (AI) — antarmuka saja
     * ------------------------------------------------------------------- */

    function initChat() {
        var form = document.querySelector('.chat-form');
        var body = document.querySelector('[data-chat]');
        if (!form || !body) {
            return;
        }

        var input = form.querySelector('input');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var message = input.value.trim();
            if (message === '') {
                return;
            }

            var emptyState = body.querySelector('.empty-state');
            if (emptyState) {
                emptyState.remove();
            }

            var userMessage = document.createElement('div');
            userMessage.className = 'chat-message chat-message--user';
            userMessage.textContent = message;
            body.appendChild(userMessage);

            var systemMessage = document.createElement('div');
            systemMessage.className = 'chat-message chat-message--system';
            systemMessage.textContent = 'AI Assistant belum aktif pada tahap ini. Integrasi API akan dibangun pada tahap berikutnya.';
            body.appendChild(systemMessage);

            input.value = '';
            body.scrollTop = body.scrollHeight;
        });
    }

    /* ---------------------------------------------------------------------
     * Tampilkan / sembunyikan kata sandi (halaman autentikasi)
     * ------------------------------------------------------------------- */

    function initPasswordToggle() {
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) {
                return;
            }

            button.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
                button.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
                input.focus();
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Loading state pada formulir (tombol submit)
     * ------------------------------------------------------------------- */

    function initLoadingForms() {
        document.querySelectorAll('form[data-loading]').forEach(function (form) {
            // Form konfirmasi mengatur loading pada tombol modal, bukan tombol
            // submit halaman (agar tombol tidak terkunci saat pengguna membatalkan).
            if (form.hasAttribute('data-confirm')) {
                return;
            }

            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                if (button && !button.disabled) {
                    button.classList.add('is-loading');
                    button.disabled = true;
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Validasi ringan pada field wajib
     * ------------------------------------------------------------------- */

    function initValidation() {
        document.querySelectorAll('input[required], textarea[required], select[required]').forEach(function (field) {
            field.addEventListener('blur', function () {
                var valid = field.value.trim() !== '';
                field.classList.toggle('is-invalid', !valid);
            });
            field.addEventListener('input', function () {
                if (field.value.trim() !== '') {
                    field.classList.remove('is-invalid');
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Filter periode laporan: tampilkan input tanggal kustom bila dipilih
     * ------------------------------------------------------------------- */

    function initRentang() {
        document.querySelectorAll('[data-rentang-form]').forEach(function (form) {
            var select = form.querySelector('[data-rentang-select]');
            var custom = form.querySelectorAll('[data-rentang-custom]');
            if (!select || custom.length === 0) {
                return;
            }

            function sync() {
                var show = select.value === 'pilih_tanggal';
                form.classList.toggle('report-filter--custom', show);
                Array.prototype.forEach.call(custom, function (field) {
                    field.classList.toggle('is-hidden', !show);
                });
            }

            select.addEventListener('change', sync);
            sync();
        });
    }

    /* ---------------------------------------------------------------------
     * Tema terang/gelap (Light default, Dark opsional)
     * ------------------------------------------------------------------- */

    function initTheme() {
        var root = document.documentElement;
        var toggles = document.querySelectorAll('[data-theme-toggle]');

        function current() {
            return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        }

        function apply(theme, persist) {
            root.setAttribute('data-theme', theme);
            root.setAttribute('data-bs-theme', theme);
            toggles.forEach(function (button) {
                var dark = theme === 'dark';
                button.setAttribute('aria-pressed', dark ? 'true' : 'false');
                button.setAttribute('aria-label', dark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
                button.setAttribute('title', dark ? 'Mode terang' : 'Mode gelap');
            });
            if (persist) {
                try {
                    window.localStorage.setItem('wificare-theme', theme);
                } catch (error) {
                    /* localStorage nonaktif: tema tetap berlaku untuk sesi ini. */
                }
            }
            /* Beri tahu grafik agar menyesuaikan warna tema. */
            window.dispatchEvent(new CustomEvent('wificare:themechange', { detail: { theme: theme } }));
        }

        apply(current(), false);

        toggles.forEach(function (button) {
            button.addEventListener('click', function () {
                apply(current() === 'dark' ? 'light' : 'dark', true);
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', function () {
        document.documentElement.classList.remove('no-js');
        initTheme();
        initSidebar();
        initSidebarSubmenus();
        initLegacyWhatsAppHash();
        initDropdowns();
        initModals();
        initConfirm();
        initToastTriggers();
        initFlashToasts();
        // Jaring pengaman untuk browser yang menyelesaikan DOMContentLoaded
        // sangat cepat; guard di dalam fungsi mencegah toast ganda.
        window.setTimeout(initFlashToasts, 0);
        initTables();
        initPagination();
        initUploads();
        initRating();
        initChat();
        initPasswordToggle();
        initLoadingForms();
        initValidation();
        initRentang();
    });

    window.WifiCare = WifiCare;
})();
