/**
 * Profil Pelanggan — ganti nomor WhatsApp.
 *
 * Timer hanya untuk tampilan. Cooldown, masa berlaku OTP, password, dan
 * kepemilikan nomor tetap divalidasi backend. Tidak ada kode OTP yang
 * disimpan atau dicatat di browser.
 */
(function () {
    'use strict';

    function formatClock(totalSeconds) {
        var value = Math.max(0, Math.floor(totalSeconds));
        var minutes = Math.floor(value / 60);
        var seconds = value % 60;

        return (minutes < 10 ? '0' : '') + minutes
            + ':' + (seconds < 10 ? '0' : '') + seconds;
    }

    function init() {
        var panel = document.querySelector('[data-change-panel]');
        if (!panel) {
            return;
        }

        var pending = panel.getAttribute('data-change-pending') === '1';
        var ready = panel.getAttribute('data-change-ready') === '1';
        var retryRemaining = parseInt(panel.getAttribute('data-change-retry-after') || '0', 10);
        var expiryRemaining = parseInt(panel.getAttribute('data-change-expires-in') || '0', 10);

        if (!isFinite(retryRemaining) || retryRemaining < 0) {
            retryRemaining = 0;
        }
        if (!isFinite(expiryRemaining) || expiryRemaining < 0) {
            expiryRemaining = 0;
        }

        var resendButton = document.querySelector('[data-change-resend]');
        var resendLabel = document.querySelector('[data-change-resend-label]');
        var verifyForm = document.querySelector('[data-change-verify-form]');
        var verifyButton = document.querySelector('[data-change-verify]');
        var codeInput = document.querySelector('[data-change-code]');
        var expiryNodes = Array.prototype.slice.call(document.querySelectorAll('[data-change-expiry]'));
        var cooldownNodes = Array.prototype.slice.call(document.querySelectorAll('[data-change-cooldown]'));
        var cooldownTimer = null;
        var expiryTimer = null;
        var codeExpired = pending && expiryRemaining <= 0;

        function syncResend() {
            var blocked = retryRemaining > 0;

            if (resendButton) {
                resendButton.disabled = !ready || blocked;
                if (blocked) {
                    resendButton.setAttribute('aria-disabled', 'true');
                } else {
                    resendButton.removeAttribute('aria-disabled');
                }
            }

            if (resendLabel) {
                resendLabel.textContent = blocked
                    ? 'Tunggu ' + formatClock(retryRemaining)
                    : 'Kirim Ulang';
            }
        }

        function syncCooldownText() {
            var message = retryRemaining > 0
                ? 'Kirim ulang tersedia dalam ' + formatClock(retryRemaining) + '.'
                : 'Anda dapat meminta kode baru.';

            cooldownNodes.forEach(function (node) {
                node.textContent = message;
            });
        }

        function syncVerifyControls() {
            var usable = pending && !codeExpired;

            if (codeInput) {
                codeInput.disabled = !usable;
            }
            if (verifyButton) {
                verifyButton.disabled = !usable;
            }
            if (verifyForm) {
                verifyForm.classList.toggle('is-disabled', !usable);
            }
        }

        function syncExpiryText() {
            var message;
            var expired = pending && expiryRemaining <= 0;

            if (pending && !expired) {
                message = 'Kode aktif. Sisa waktu ' + formatClock(expiryRemaining) + '.';
            } else if (expired) {
                message = 'Kode sudah kedaluwarsa. Mulai ulang permintaan ganti nomor.';
            } else {
                message = 'Belum ada kode aktif.';
            }

            expiryNodes.forEach(function (node) {
                node.textContent = message;
                node.classList.toggle('is-expired', expired);
            });
        }

        function tickCooldown() {
            if (retryRemaining <= 0) {
                if (cooldownTimer !== null) {
                    window.clearInterval(cooldownTimer);
                    cooldownTimer = null;
                }
                syncResend();
                syncCooldownText();
                return;
            }

            retryRemaining -= 1;
            syncResend();
            syncCooldownText();

            if (retryRemaining === 0 && cooldownTimer !== null) {
                window.clearInterval(cooldownTimer);
                cooldownTimer = null;
            }
        }

        function tickExpiry() {
            if (!pending || codeExpired) {
                if (expiryTimer !== null) {
                    window.clearInterval(expiryTimer);
                    expiryTimer = null;
                }
                return;
            }

            if (expiryRemaining > 0) {
                expiryRemaining -= 1;
            }
            if (expiryRemaining <= 0) {
                expiryRemaining = 0;
                codeExpired = true;
                syncVerifyControls();
                syncExpiryText();
                if (expiryTimer !== null) {
                    window.clearInterval(expiryTimer);
                    expiryTimer = null;
                }
                return;
            }

            syncExpiryText();
        }

        if (resendButton) {
            resendButton.addEventListener('click', function () {
                if (resendButton.disabled) {
                    return;
                }
                resendButton.classList.add('is-loading');
                resendButton.setAttribute('aria-busy', 'true');
            });
        }

        if (codeInput) {
            codeInput.addEventListener('input', function () {
                var cleaned = codeInput.value.replace(/\D+/g, '').slice(0, 6);
                if (codeInput.value !== cleaned) {
                    codeInput.value = cleaned;
                }
            });
        }

        syncResend();
        syncCooldownText();
        syncVerifyControls();
        syncExpiryText();

        if (retryRemaining > 0) {
            cooldownTimer = window.setInterval(tickCooldown, 1000);
        }
        if (pending && expiryRemaining > 0) {
            expiryTimer = window.setInterval(tickExpiry, 1000);
        }

        if (panel.getAttribute('data-change-auto-open') === '1') {
            var trigger = panel.querySelector('[data-change-auto-trigger]');
            var modal = document.querySelector('[data-modal="profilGantiNomorModal"]');

            if (trigger && modal) {
                var attempts = 0;
                var openWhenReady = function () {
                    if (!modal.hidden) {
                        return;
                    }

                    // main.js memasang delegasi modal saat DOMContentLoaded.
                    // Ulangi sebentar agar auto-open tidak kalah balapan.
                    trigger.click();
                    attempts += 1;
                    if (modal.hidden && attempts < 20) {
                        window.setTimeout(openWhenReady, 100);
                    }
                };

                window.setTimeout(openWhenReady, 0);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
