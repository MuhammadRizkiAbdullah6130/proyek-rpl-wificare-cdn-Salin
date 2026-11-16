/**
 * WiFiCare CDN — Floating AI Assistant widget (Tahap 10 — update UI).
 *
 * Mengelola tombol melayang (buka/tutup/perkecil), kirim pesan ke endpoint PHP
 * yang sudah ada (POST JSON + CSRF), indikator memuat, dan penanganan error.
 *
 * Keamanan: isi jawaban AI selalu dimasukkan sebagai TEKS (textContent), tidak
 * pernah sebagai HTML. Token CSRF diambil dari atribut formulir (server-side),
 * kunci API tidak pernah ada di sisi klien. Tidak ada jawaban AI yang
 * di-hardcode. Isi percakapan dipertahankan selama halaman masih aktif.
 */
(function () {
    'use strict';

    var REQUEST_TIMEOUT_MS = 30000;

    function text(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    function bubble(role, content) {
        // Gunakan perender aman bila tersedia (teks kaya tanpa HTML mentah).
        if (window.WifiCareAiRender && typeof window.WifiCareAiRender.createRichBubble === 'function') {
            return window.WifiCareAiRender.createRichBubble(role, content);
        }

        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--' + role;

        var body = document.createElement('div');
        body.className = 'chat-bubble-body chat-bubble-body--plain';
        // textContent → aman terhadap HTML/script dari respons AI.
        body.textContent = text(content);

        wrap.appendChild(body);

        return wrap;
    }

    function replyBubble(reply, tickets, tables) {
        if (window.WifiCareAiRender && typeof window.WifiCareAiRender.createReplyBubble === 'function') {
            return window.WifiCareAiRender.createReplyBubble(reply, tickets, tables);
        }

        return bubble('assistant', reply);
    }

    function copyToClipboard(value) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(value);
        }

        return new Promise(function (resolve, reject) {
            try {
                var area = document.createElement('textarea');
                area.value = value;
                area.setAttribute('readonly', 'readonly');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(area);
                ok ? resolve() : reject(new Error('copy failed'));
            } catch (error) {
                reject(error);
            }
        });
    }

    function attachCopy(bubbleEl, copyText) {
        if (!bubbleEl || bubbleEl.querySelector('[data-aiw-copy]')) {
            return;
        }

        var value = text(copyText).trim();
        if (value === '') {
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'aiw-copy';
        button.setAttribute('data-aiw-copy', '1');
        button.setAttribute('aria-label', 'Salin jawaban');
        button.title = 'Salin jawaban';
        button.textContent = 'Salin';

        button.addEventListener('click', function () {
            copyToClipboard(value)
                .then(function () {
                    button.textContent = 'Tersalin';
                    button.classList.add('is-copied');
                    window.setTimeout(function () {
                        button.textContent = 'Salin';
                        button.classList.remove('is-copied');
                    }, 1600);
                })
                .catch(function () {
                    button.textContent = 'Gagal';
                    window.setTimeout(function () {
                        button.textContent = 'Salin';
                    }, 1600);
                });
        });

        bubbleEl.appendChild(button);
    }

    function attachCopyToExisting(container) {
        container.querySelectorAll('.chat-bubble--assistant').forEach(function (bubbleEl) {
            if (bubbleEl.querySelector('[data-aiw-copy]')) {
                return;
            }
            var body = bubbleEl.querySelector('.chat-bubble-body');
            attachCopy(bubbleEl, body ? body.textContent : '');
        });
    }

    function loadingBubble() {
        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--assistant chat-bubble--loading';

        var body = document.createElement('div');
        body.className = 'chat-bubble-body';
        body.innerHTML = '<span class="typing-dot"></span><span class="typing-dot"></span>'
            + '<span class="typing-dot"></span>';

        wrap.appendChild(body);

        return wrap;
    }

    function init() {
        var root = document.querySelector('[data-ai-widget]');
        if (!root) {
            return;
        }

        var fab = root.querySelector('[data-aiw-toggle]');
        var panel = root.querySelector('[data-aiw-panel]');
        var messages = root.querySelector('[data-aiw-messages]');
        var form = root.querySelector('[data-aiw-form]');
        var input = form ? form.querySelector('[data-aiw-input]') : null;
        var sendButton = form ? form.querySelector('[data-aiw-send]') : null;
        var closeButton = root.querySelector('[data-aiw-close]');
        var minimizeButton = root.querySelector('[data-aiw-minimize]');
        var clearButton = root.querySelector('[data-aiw-clear]');

        if (!fab || !panel || !messages) {
            return;
        }

        var endpoint = form ? (form.getAttribute('data-endpoint') || '') : '';
        var token = form ? (form.getAttribute('data-token') || '') : '';
        var clearEndpoint = form ? (form.getAttribute('data-clear-endpoint') || '') : '';
        var initialMarkup = messages.innerHTML;
        var pending = false;
        var lastFocus = null;

        function bindQuickQuestions() {
            messages.querySelectorAll('[data-aiw-quick]').forEach(function (button) {
                button.addEventListener('click', function () {
                    send(button.getAttribute('data-aiw-quick') || '');
                });
            });
        }

        function bindCopyButtons() {
            attachCopyToExisting(messages);
        }

        function resetConversation() {
            messages.innerHTML = initialMarkup;
            bindQuickQuestions();
            bindCopyButtons();
            scrollToBottom();
        }

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        function hideQuick() {
            var quick = messages.querySelector('[data-aiw-quick-list]');
            if (quick) {
                quick.remove();
            }
        }

        function setPending(state) {
            pending = state;
            if (sendButton) {
                sendButton.disabled = state;
                sendButton.classList.toggle('is-loading', state);
                sendButton.setAttribute('aria-busy', state ? 'true' : 'false');
            }
            if (input) {
                input.disabled = state;
            }
        }

        function append(role, content) {
            messages.appendChild(bubble(role, content));
            scrollToBottom();
        }

        function isOpen() {
            return !panel.hidden;
        }

        function open() {
            if (isOpen()) {
                return;
            }
            lastFocus = document.activeElement;
            panel.hidden = false;
            panel.classList.remove('is-minimized');
            if (minimizeButton) {
                minimizeButton.setAttribute('aria-expanded', 'true');
            }
            fab.setAttribute('aria-expanded', 'true');
            // Tidak mengirim permintaan apa pun saat hanya membuka widget.
            if (input && !input.disabled) {
                input.focus();
            } else if (closeButton) {
                closeButton.focus();
            }
        }

        function close() {
            if (!isOpen()) {
                return;
            }
            panel.hidden = true;
            fab.setAttribute('aria-expanded', 'false');
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            } else {
                fab.focus();
            }
        }

        function minimize() {
            var minimized = panel.classList.toggle('is-minimized');
            if (minimizeButton) {
                minimizeButton.setAttribute('aria-expanded', minimized ? 'false' : 'true');
            }
            if (!minimized && input && !input.disabled) {
                input.focus();
            }
        }

        function autoResize() {
            if (!input) {
                return;
            }
            input.style.height = 'auto';
            input.style.height = input.scrollHeight + 'px';
        }

        function send(message) {
            if (pending) {
                return;
            }

            var value = text(message).trim();
            if (value === '' || !input || input.disabled) {
                return;
            }

            hideQuick();
            append('user', value);
            input.value = '';
            autoResize();

            setPending(true);
            var loading = loadingBubble();
            messages.appendChild(loading);
            scrollToBottom();

            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var timer = controller
                ? window.setTimeout(function () {
                    controller.abort();
                }, REQUEST_TIMEOUT_MS)
                : null;

            var options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ message: value, _token: token })
            };
            if (controller) {
                options.signal = controller.signal;
            }

            fetch(endpoint, options)
                .then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, error: 'Respons layanan tidak dapat dibaca.' };
                    });
                })
                .then(function (data) {
                    if (timer) {
                        window.clearTimeout(timer);
                    }
                    loading.remove();
                    if (data && data.ok) {
                        var node = replyBubble(data.reply || '', data.tickets, data.tables);
                        messages.appendChild(node);
                        attachCopy(node, data.reply || '');
                        scrollToBottom();
                    } else if (data && data.error) {
                        append('error', data.error);
                    } else {
                        append('error', 'Terjadi kesalahan. Silakan coba lagi.');
                    }
                })
                .catch(function (error) {
                    if (timer) {
                        window.clearTimeout(timer);
                    }
                    loading.remove();
                    var aborted = error && error.name === 'AbortError';
                    append(
                        'error',
                        aborted
                            ? 'Layanan AI tidak merespons tepat waktu. Silakan coba lagi.'
                            : 'Tidak dapat menghubungi layanan AI. Periksa koneksi internet Anda.'
                    );
                })
                .then(function () {
                    setPending(false);
                    if (input && !input.disabled) {
                        input.focus();
                    }
                    scrollToBottom();
                });
        }

        function clearConversation() {
            if (pending) {
                return;
            }

            if (clearButton) {
                clearButton.disabled = true;
            }

            if (clearEndpoint === '') {
                resetConversation();
                if (clearButton) {
                    clearButton.disabled = false;
                }
                return;
            }

            fetch(clearEndpoint, {
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
                        return { ok: false, error: 'Respons server tidak dapat dibaca.' };
                    });
                })
                .then(function (data) {
                    if (data && data.ok) {
                        resetConversation();
                    } else {
                        append('error', (data && data.error) ? data.error : 'Gagal membersihkan percakapan.');
                    }
                })
                .catch(function () {
                    append('error', 'Tidak dapat menghubungi server untuk membersihkan percakapan.');
                })
                .then(function () {
                    if (clearButton) {
                        clearButton.disabled = false;
                    }
                    if (input && !input.disabled) {
                        input.focus();
                    }
                });
        }

        fab.addEventListener('click', function () {
            if (!isOpen()) {
                open();
            } else if (panel.classList.contains('is-minimized')) {
                minimize();
            } else {
                close();
            }
        });

        if (closeButton) {
            closeButton.addEventListener('click', close);
        }

        if (minimizeButton) {
            minimizeButton.addEventListener('click', minimize);
        }

        if (clearButton) {
            clearButton.addEventListener('click', clearConversation);
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                send(input ? input.value : '');
            });
        }

        if (input) {
            input.addEventListener('input', autoResize);
            input.addEventListener('keydown', function (event) {
                // Enter mengirim; Shift+Enter membuat baris baru.
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    send(input.value);
                }
            });
        }

        bindQuickQuestions();
        bindCopyButtons();

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen()) {
                close();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
