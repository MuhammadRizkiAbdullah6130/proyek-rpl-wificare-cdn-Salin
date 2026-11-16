/**
 * WiFiCare CDN — AI Assistant chat (Tahap 9).
 *
 * Mengirim pesan ke endpoint backend PHP (POST JSON + CSRF), menampilkan
 * indikator memuat, dan menangani error. Isi jawaban AI selalu dimasukkan
 * sebagai TEKS (textContent), tidak pernah sebagai HTML, sehingga respons tidak
 * dapat menyuntikkan markup/script.
 *
 * Tidak ada jawaban AI yang di-hardcode di sisi klien.
 */
(function () {
    'use strict';

    function escapeText(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    function createBubble(role, text) {
        if (window.WifiCareAiRender && typeof window.WifiCareAiRender.createRichBubble === 'function') {
            return window.WifiCareAiRender.createRichBubble(role, text);
        }

        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble-body chat-bubble-body--plain';
        // textContent → aman terhadap HTML/script dari respons AI.
        bubble.textContent = escapeText(text);

        wrap.appendChild(bubble);

        return wrap;
    }

    function createReplyBubble(reply, tickets, tables) {
        if (window.WifiCareAiRender && typeof window.WifiCareAiRender.createReplyBubble === 'function') {
            return window.WifiCareAiRender.createReplyBubble(reply, tickets, tables);
        }

        return createBubble('assistant', reply);
    }

    function createLoadingBubble() {
        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--assistant chat-bubble--loading';
        wrap.setAttribute('data-ai-loading', '');

        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble-body';
        bubble.innerHTML = '<span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>';

        wrap.appendChild(bubble);

        return wrap;
    }

    function init() {
        var form = document.querySelector('[data-ai-form]');
        if (!form) {
            return;
        }

        var messages = document.querySelector('[data-ai-messages]');
        var input = form.querySelector('[data-ai-input]');
        var sendButton = form.querySelector('[data-ai-send]');
        var endpoint = form.getAttribute('data-endpoint') || '';
        var token = form.getAttribute('data-token') || '';
        var hint = document.querySelector('[data-ai-hint]');
        var pending = false;

        function clearEmptyState() {
            var empty = messages.querySelector('.empty-state');
            if (empty) {
                empty.remove();
            }
        }

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
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

        function autoResize() {
            if (!input) {
                return;
            }
            input.style.height = 'auto';
            input.style.height = input.scrollHeight + 'px';
        }

        function showError(text) {
            clearEmptyState();
            messages.appendChild(createBubble('error', text));
            scrollToBottom();
        }

        function send(message) {
            if (pending) {
                return;
            }

            var text = (message || '').trim();
            if (text === '') {
                return;
            }

            clearEmptyState();
            messages.appendChild(createBubble('user', text));
            if (input) {
                input.value = '';
            }
            scrollToBottom();

            setPending(true);
            var loading = createLoadingBubble();
            messages.appendChild(loading);
            scrollToBottom();

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ message: text, _token: token })
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, error: 'Respons layanan tidak dapat dibaca.' };
                    });
                })
                .then(function (data) {
                    loading.remove();
                    if (data && data.ok) {
                        messages.appendChild(createReplyBubble(data.reply || '', data.tickets, data.tables));
                    } else if (data && data.error) {
                        messages.appendChild(createBubble('error', data.error));
                    } else {
                        messages.appendChild(createBubble('error', 'Terjadi kesalahan. Silakan coba lagi.'));
                    }
                })
                .catch(function () {
                    loading.remove();
                    messages.appendChild(createBubble('error', 'Tidak dapat menghubungi layanan AI. Periksa koneksi internet Anda.'));
                })
                .then(function () {
                    setPending(false);
                    if (input) {
                        input.focus();
                    }
                    if (hint) {
                        hint.classList.remove('is-error');
                    }
                    scrollToBottom();
                });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(input ? input.value : '');
        });

        if (input) {
            input.addEventListener('input', autoResize);
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    send(input.value);
                }
            });
        }

        document.querySelectorAll('[data-ai-quick]').forEach(function (button) {
            button.addEventListener('click', function () {
                var question = button.getAttribute('data-ai-quick') || '';
                if (input) {
                    input.value = question;
                }
                send(question);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
