/**
 * Profil Pelanggan — verifikasi nomor WhatsApp.
 *
 * Timer di sini hanya untuk tampilan. Cooldown dan masa berlaku kode tetap
 * divalidasi backend. Tidak ada kode OTP yang disimpan atau dicatat di browser.
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
        var panel = document.querySelector('[data-otp-panel]');
        if (!panel) {
            return;
        }

        var ready = panel.getAttribute('data-otp-ready') === '1';
        var hasActive = panel.getAttribute('data-otp-has-active') === '1';
        var retryRemaining = parseInt(panel.getAttribute('data-otp-retry-after') || '0', 10);
        var expiryRemaining = parseInt(panel.getAttribute('data-otp-expires-in') || '0', 10);

        if (!isFinite(retryRemaining) || retryRemaining < 0) {
            retryRemaining = 0;
        }
        if (!isFinite(expiryRemaining) || expiryRemaining < 0) {
            expiryRemaining = 0;
        }

        var sendButtons = Array.prototype.slice.call(document.querySelectorAll('[data-otp-send]'));
        var resendButtons = Array.prototype.slice.call(document.querySelectorAll('[data-otp-resend]'));
        var actionButtons = sendButtons.concat(resendButtons);
        var sendLabel = document.querySelector('[data-otp-send-label]');
        var resendLabel = document.querySelector('[data-otp-resend-label]');
        var verifyForm = document.querySelector('[data-otp-verify-form]');
        var verifyButton = document.querySelector('[data-otp-verify]');
        var codeInput = document.querySelector('[data-otp-code]');
        var expiryNodes = Array.prototype.slice.call(document.querySelectorAll('[data-otp-expiry]'));
        var cooldownNodes = Array.prototype.slice.call(document.querySelectorAll('[data-otp-cooldown]'));
        var cooldownTimer = null;
        var expiryTimer = null;
        var codeExpired = hasActive && expiryRemaining <= 0;

        function syncActionButtons() {
            var blocked = retryRemaining > 0;

            actionButtons.forEach(function (button) {
                button.disabled = !ready || blocked;
                if (blocked) {
                    button.setAttribute('aria-disabled', 'true');
                } else {
                    button.removeAttribute('aria-disabled');
                }
            });

            if (sendLabel) {
                if (blocked) {
                    sendLabel.textContent = (hasActive ? 'Kirim Ulang' : 'Kirim Kode')
                        + ' (' + formatClock(retryRemaining) + ')';
                } else {
                    sendLabel.textContent = hasActive ? 'Kirim Ulang Kode OTP' : 'Kirim Kode OTP';
                }
            }

            if (resendLabel) {
                resendLabel.textContent = blocked
                    ? 'Tunggu ' + formatClock(retryRemaining)
                    : (hasActive ? 'Kirim Ulang' : 'Kirim Kode');
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
            var usable = hasActive && !codeExpired;

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
            var expired = hasActive && expiryRemaining <= 0;

            if (hasActive && !expired) {
                message = 'Kode aktif. Sisa waktu ' + formatClock(expiryRemaining) + '.';
            } else if (expired) {
                message = 'Kode sudah kedaluwarsa. Minta kode baru.';
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
                syncActionButtons();
                syncCooldownText();
                return;
            }

            retryRemaining -= 1;
            syncActionButtons();
            syncCooldownText();

            if (retryRemaining === 0 && cooldownTimer !== null) {
                window.clearInterval(cooldownTimer);
                cooldownTimer = null;
            }
        }

        function tickExpiry() {
            if (!hasActive || codeExpired) {
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

        resendButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.disabled) {
                    return;
                }
                // Jangan menonaktifkan tombol submit di dalam event click:
                // biarkan browser memproses submit form terlebih dahulu.
                button.classList.add('is-loading');
                button.setAttribute('aria-busy', 'true');
            });
        });

        if (codeInput) {
            codeInput.addEventListener('input', function () {
                var cleaned = codeInput.value.replace(/\D+/g, '').slice(0, 6);
                if (codeInput.value !== cleaned) {
                    codeInput.value = cleaned;
                }
            });
        }

        syncActionButtons();
        syncCooldownText();
        syncVerifyControls();
        syncExpiryText();

        if (retryRemaining > 0) {
            cooldownTimer = window.setInterval(tickCooldown, 1000);
        }
        if (hasActive && expiryRemaining > 0) {
            expiryTimer = window.setInterval(tickExpiry, 1000);
        }

        if (panel.getAttribute('data-otp-auto-open') === '1') {
            var trigger = panel.querySelector('[data-otp-auto-trigger]');
            var modal = document.querySelector('[data-modal="profilOtpModal"]');

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
