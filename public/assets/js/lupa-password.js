/**
 * Lupa Password Pelanggan — timer kirim ulang dan validasi input OTP.
 *
 * Timer hanya membantu tampilan. Semua cooldown, masa berlaku, dan jumlah
 * percobaan tetap ditegakkan di server.
 */
(function () {
    'use strict';

    var resendButton = document.querySelector('[data-resend-button]');
    var resendHint = document.querySelector('[data-resend-hint]');

    if (resendButton) {
        var remaining = parseInt(resendButton.getAttribute('data-resend-after') || '0', 10);
        if (!isFinite(remaining) || remaining < 0) {
            remaining = 0;
        }

        var label = resendButton.querySelector('[data-resend-label]');

        var renderResend = function () {
            if (remaining > 0) {
                resendButton.disabled = true;
                if (label) {
                    label.textContent = 'Kirim Ulang (' + remaining + 's)';
                }
                if (resendHint) {
                    resendHint.textContent = 'Tunggu ' + remaining + ' detik sebelum meminta kode baru.';
                }
                return;
            }

            resendButton.disabled = false;
            if (label) {
                label.textContent = 'Kirim Ulang Kode';
            }
            if (resendHint) {
                resendHint.textContent = 'Belum menerima kode? Anda dapat meminta kode baru.';
            }
        };

        renderResend();
        if (remaining > 0) {
            var resendTimer = window.setInterval(function () {
                remaining = Math.max(0, remaining - 1);
                renderResend();
                if (remaining === 0) {
                    window.clearInterval(resendTimer);
                }
            }, 1000);
        }
    }

    var authTimer = document.querySelector('[data-auth-timer]');
    if (authTimer) {
        var authSeconds = parseInt(authTimer.getAttribute('data-auth-seconds') || '0', 10);
        if (isFinite(authSeconds) && authSeconds > 0) {
            var updateAuthTimer = function () {
                var minutes = Math.max(1, Math.ceil(authSeconds / 60));
                authTimer.textContent = String(minutes);
            };
            updateAuthTimer();
            var authInterval = window.setInterval(function () {
                authSeconds = Math.max(0, authSeconds - 1);
                updateAuthTimer();
                if (authSeconds === 0) {
                    window.clearInterval(authInterval);
                }
            }, 1000);
        }
    }

    var otpInput = document.getElementById('reset-otp');
    if (otpInput) {
        otpInput.addEventListener('input', function () {
            var digits = otpInput.value.replace(/\D+/g, '').slice(0, 6);
            if (otpInput.value !== digits) {
                otpInput.value = digits;
            }
        });
    }
})();
