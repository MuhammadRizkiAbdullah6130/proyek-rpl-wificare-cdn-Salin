/**
 * WiFiCare CDN — Pengaturan AI Chatbot (multi-provider).
 *
 * Menangani: tampil/sembunyikan API key baru, label status toggle, pergantian
 * provider (model/base mengikuti provider), dan tombol "Tes Koneksi" yang
 * memanggil endpoint backend dengan API sungguhan.
 *
 * CATATAN KEAMANAN: API key yang sedang diketik HANYA dikirim ke backend
 * WiFiCare (same-origin) untuk diuji/dienkripsi; tidak pernah disimpan di
 * localStorage, cookie, atau variabel global.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function toast(message, type) {
        if (window.WifiCare && typeof window.WifiCare.toast === 'function') {
            window.WifiCare.toast(message, type || 'info');
        }
    }

    ready(function () {
        var form = document.getElementById('aiSettingsForm');
        if (!form) {
            return;
        }

        var token = '';
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            token = meta.getAttribute('content') || '';
        }
        var hiddenToken = form.querySelector('input[name="_token"]');
        if (hiddenToken && hiddenToken.value) {
            token = hiddenToken.value;
        }

        var keyInput = document.getElementById('aiApiKey');
        var keyToggle = document.getElementById('aiKeyToggle');
        var keyToggleText = document.getElementById('aiKeyToggleText');

        if (keyInput && keyToggle) {
            keyToggle.addEventListener('click', function () {
                var reveal = keyInput.type === 'password';
                keyInput.type = reveal ? 'text' : 'password';
                keyToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                if (keyToggleText) {
                    keyToggleText.textContent = reveal ? 'Sembunyikan' : 'Tampilkan';
                }
            });
        }

        var enabled = document.getElementById('aiEnabled');
        var enabledLabel = document.getElementById('aiEnabledLabel');
        if (enabled && enabledLabel) {
            enabled.addEventListener('change', function () {
                enabledLabel.textContent = enabled.checked ? 'Aktif' : 'Nonaktif';
            });
        }

        var providerSelect = document.getElementById('aiProvider');
        var modelInput = document.getElementById('aiModel');
        var modelList = document.getElementById('aiModelList');
        var baseInput = document.getElementById('aiBaseUrl');
        var keyLabel = document.getElementById('aiProviderKeyLabel');

        function selectedOption() {
            if (!providerSelect) {
                return null;
            }
            return providerSelect.options[providerSelect.selectedIndex] || null;
        }

        if (providerSelect) {
            providerSelect.addEventListener('change', function () {
                var option = selectedOption();
                if (!option) {
                    return;
                }
                var provider = providerSelect.value;
                var model = option.getAttribute('data-model') || '';
                var base = option.getAttribute('data-base') || '';
                var hasKey = option.getAttribute('data-has-key') === '1';
                var label = option.getAttribute('data-label') || provider;

                if (modelInput) {
                    modelInput.value = model;
                }
                if (baseInput) {
                    baseInput.value = base;
                }
                if (keyLabel) {
                    keyLabel.textContent = label;
                }
                if (keyInput) {
                    keyInput.value = '';
                    keyInput.placeholder = hasKey
                        ? 'Biarkan kosong untuk mempertahankan key ' + label
                        : 'Tempel API key ' + label;
                }
                if (modelList) {
                    // Saran model tetap ditampilkan; validasi sebenarnya di backend.
                }
            });
        }

        var testBtn = document.getElementById('aiTestBtn');
        var testBtnText = document.getElementById('aiTestBtnText');
        var resultEl = document.getElementById('aiTestResult');

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                if (testBtn.disabled) {
                    return;
                }

                var originalLabel = testBtnText ? testBtnText.textContent : '';
                var provider = providerSelect ? providerSelect.value : 'gemini';
                var providerLabel = providerSelect && selectedOption()
                    ? (selectedOption().getAttribute('data-label') || provider)
                    : provider;

                testBtn.disabled = true;
                if (testBtnText) {
                    testBtnText.textContent = 'Menguji…';
                }
                if (resultEl) {
                    resultEl.textContent = 'Menguji koneksi ke ' + providerLabel + '…';
                    resultEl.className = 'form-hint';
                }

                var payload = {
                    _token: token,
                    provider: provider,
                    api_key: keyInput ? keyInput.value : '',
                    model: modelInput ? modelInput.value : '',
                    base_url: baseInput ? baseInput.value : ''
                };

                fetch(testBtn.getAttribute('data-test-url'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                })
                    .then(function (response) {
                        return response.json().catch(function () {
                            return { ok: false, message: 'Respons pengujian tidak dapat dibaca.' };
                        });
                    })
                    .then(function (data) {
                        var ok = !!(data && data.ok);
                        var message = (data && data.message) ? data.message : 'Gagal terhubung ke layanan AI.';

                        if (resultEl) {
                            resultEl.textContent = message;
                            resultEl.className = 'form-hint ' + (ok ? 'is-success' : 'is-error');
                        }
                        toast(message, ok ? 'success' : 'error');
                    })
                    .catch(function () {
                        var message = 'Gagal menghubungi server pengujian.';
                        if (resultEl) {
                            resultEl.textContent = message;
                            resultEl.className = 'form-hint is-error';
                        }
                        toast(message, 'error');
                    })
                    .then(function () {
                        testBtn.disabled = false;
                        if (testBtnText) {
                            testBtnText.textContent = originalLabel || 'Tes Koneksi Provider';
                        }
                    });
            });
        }

        var dbTestBtn = document.getElementById('aiDbTestBtn');
        var dbTestBtnText = document.getElementById('aiDbTestBtnText');
        var dbResultEl = document.getElementById('aiDbTestResult');

        if (dbTestBtn) {
            dbTestBtn.addEventListener('click', function () {
                if (dbTestBtn.disabled) {
                    return;
                }

                var originalLabel = dbTestBtnText ? dbTestBtnText.textContent : '';
                dbTestBtn.disabled = true;
                if (dbTestBtnText) {
                    dbTestBtnText.textContent = 'Menguji...';
                }
                if (dbResultEl) {
                    dbResultEl.textContent = 'Menguji koneksi MySQL read-only...';
                    dbResultEl.className = 'form-hint';
                }

                fetch(dbTestBtn.getAttribute('data-test-url'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ _token: token })
                })
                    .then(function (response) {
                        return response.json().catch(function () {
                            return { ok: false, message: 'Respons pengujian tidak dapat dibaca.' };
                        });
                    })
                    .then(function (data) {
                        var ok = !!(data && data.ok);
                        var message = (data && data.message) ? data.message : 'Gagal menguji database.';

                        if (dbResultEl) {
                            dbResultEl.textContent = message;
                            dbResultEl.className = 'form-hint ' + (ok ? 'is-success' : 'is-error');
                        }
                        toast(message, ok ? 'success' : 'error');
                    })
                    .catch(function () {
                        var message = 'Gagal menghubungi server pengujian database.';
                        if (dbResultEl) {
                            dbResultEl.textContent = message;
                            dbResultEl.className = 'form-hint is-error';
                        }
                        toast(message, 'error');
                    })
                    .then(function () {
                        dbTestBtn.disabled = false;
                        if (dbTestBtnText) {
                            dbTestBtnText.textContent = originalLabel || 'Tes MySQL Read-only';
                        }
                    });
            });
        }
    });
})();
