/**
 * WiFiCare CDN — Perender respons AI yang aman (Revisi 2).
 *
 * Mengubah teks jawaban AI menjadi DOM terstruktur TANPA mengeksekusi HTML:
 * - penekanan **tebal** dirender sebagai <strong> (simbol bintang tidak tampil);
 * - judul "#", daftar "-"/"•"/bernomor dirender rapi;
 * - paragraf dipisahkan dengan jarak yang nyaman.
 *
 * Data tiket TIDAK diparsing dari teks AI. Kartu tiket dibangun dari array
 * JSON terkontrol yang dikirim backend (`tickets`), sehingga isi kartu bersifat
 * otoritatif dan tetap terikat pada otorisasi RBAC di server.
 *
 * Tidak ada innerHTML dari respons AI; seluruh teks dimasukkan via textContent.
 */
(function () {
    'use strict';

    var STATUS_CLASS = {
        menunggu_verifikasi: 'status-menunggu-verifikasi',
        diproses_cs: 'status-diproses-cs',
        menunggu_teknisi: 'status-menunggu-teknisi',
        dalam_penanganan: 'status-dalam-penanganan',
        selesai: 'status-selesai',
        ditolak: 'status-ditolak'
    };

    function str(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    /* --- Teks inline: ubah **tebal** menjadi <strong> tanpa simbol bintang --- */
    function appendInline(parent, text) {
        var re = /\*\*(.+?)\*\*/g;
        var last = 0;
        var match;

        while ((match = re.exec(text)) !== null) {
            if (match.index > last) {
                parent.appendChild(document.createTextNode(text.slice(last, match.index)));
            }
            var strong = document.createElement('strong');
            strong.textContent = match[1];
            parent.appendChild(strong);
            last = re.lastIndex;
        }

        if (last < text.length) {
            parent.appendChild(document.createTextNode(text.slice(last)));
        }
    }

    /* --- Blok: judul, daftar, dan paragraf --- */
    function renderRich(container, content) {
        var lines = str(content).replace(/\r\n?/g, '\n').split('\n');
        var para = [];
        var listEl = null;
        var listType = null;

        function flushPara() {
            if (para.length === 0) {
                return;
            }
            var p = document.createElement('p');
            p.className = 'chat-para';
            appendInline(p, para.join(' '));
            container.appendChild(p);
            para = [];
        }

        function flushList() {
            listEl = null;
            listType = null;
        }

        lines.forEach(function (raw) {
            var trimmed = raw.replace(/\s+$/, '').trim();

            if (trimmed === '') {
                flushPara();
                flushList();
                return;
            }

            var heading = trimmed.match(/^#{1,6}\s+(.*)$/);
            if (heading) {
                flushPara();
                flushList();
                var h = document.createElement('p');
                h.className = 'chat-h';
                appendInline(h, heading[1]);
                container.appendChild(h);
                return;
            }

            var unordered = trimmed.match(/^[-*•]\s+(.*)$/);
            var ordered = trimmed.match(/^(\d+)[.)]\s+(.*)$/);
            if (unordered || ordered) {
                flushPara();
                var type = unordered ? 'ul' : 'ol';
                if (!listEl || listType !== type) {
                    listEl = document.createElement(type);
                    listEl.className = 'chat-list';
                    container.appendChild(listEl);
                    listType = type;
                }
                var li = document.createElement('li');
                appendInline(li, unordered ? unordered[1] : ordered[2]);
                listEl.appendChild(li);
                return;
            }

            flushList();
            para.push(trimmed);
        });

        flushPara();
        flushList();

        if (container.childNodes.length === 0) {
            var fallback = document.createElement('p');
            fallback.className = 'chat-para';
            fallback.textContent = str(content);
            container.appendChild(fallback);
        }
    }

    /* --- Tabel data terstruktur (dari JSON backend, bukan HTML AI) --- */
    function safeTone(value) {
        var tone = str(value).toLowerCase();
        return /^[a-z0-9_-]{1,32}$/.test(tone) ? tone : 'netral';
    }

    function renderTables(container, tables) {
        if (!Array.isArray(tables) || tables.length === 0) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'chat-tables';

        tables.slice(0, 6).forEach(function (table) {
            if (!table || typeof table !== 'object') {
                return;
            }
            var columns = Array.isArray(table.columns) ? table.columns.slice(0, 8) : [];
            var rows = Array.isArray(table.rows) ? table.rows.slice(0, 50) : [];
            if (columns.length === 0) {
                return;
            }

            var section = document.createElement('div');
            section.className = 'chat-table-card';

            var title = str(table.title).trim();
            if (title !== '') {
                var heading = document.createElement('div');
                heading.className = 'chat-table-title';
                heading.textContent = title;
                section.appendChild(heading);
            }

            var scroll = document.createElement('div');
            scroll.className = 'chat-table-scroll';

            var tableEl = document.createElement('table');
            tableEl.className = 'chat-table';

            var thead = document.createElement('thead');
            var headRow = document.createElement('tr');
            columns.forEach(function (column) {
                if (!column || typeof column !== 'object') {
                    return;
                }
                var th = document.createElement('th');
                th.scope = 'col';
                th.textContent = str(column.label || column.key);
                headRow.appendChild(th);
            });
            thead.appendChild(headRow);
            tableEl.appendChild(thead);

            var tbody = document.createElement('tbody');
            if (rows.length === 0) {
                var emptyRow = document.createElement('tr');
                var emptyCell = document.createElement('td');
                emptyCell.colSpan = columns.length;
                emptyCell.className = 'chat-table-empty';
                emptyCell.textContent = 'Tidak ada data.';
                emptyRow.appendChild(emptyCell);
                tbody.appendChild(emptyRow);
            } else {
                rows.forEach(function (row) {
                    if (!row || typeof row !== 'object') {
                        return;
                    }
                    var tr = document.createElement('tr');
                    columns.forEach(function (column) {
                        if (!column || typeof column !== 'object') {
                            return;
                        }
                        var key = str(column.key);
                        var value = row[key];
                        var td = document.createElement('td');
                        if (column.type === 'number') {
                            td.className = 'chat-table-number';
                        }
                        if (column.type === 'badge') {
                            var tone = safeTone(row[str(column.tone_key)] || 'netral');
                            var badge = document.createElement('span');
                            badge.className = 'chat-badge chat-badge--' + tone;
                            badge.textContent = str(value);
                            td.appendChild(badge);
                        } else {
                            td.textContent = str(value);
                        }
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                });
            }
            tableEl.appendChild(tbody);
            scroll.appendChild(tableEl);
            section.appendChild(scroll);
            wrap.appendChild(section);
        });

        if (wrap.childNodes.length > 0) {
            container.appendChild(wrap);
        }
    }

    /* --- Kartu tiket terstruktur --- */
    function addRow(card, label, value) {
        var text = str(value);
        if (text === '') {
            return;
        }

        var row = document.createElement('div');
        row.className = 'chat-ticket-row';

        var labelEl = document.createElement('span');
        labelEl.className = 'chat-ticket-label';
        labelEl.textContent = label;

        var valueEl = document.createElement('span');
        valueEl.className = 'chat-ticket-value';
        valueEl.textContent = text;

        row.appendChild(labelEl);
        row.appendChild(valueEl);
        card.appendChild(row);
    }

    function renderCards(container, tickets) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-cards';

        tickets.forEach(function (ticket) {
            if (!ticket || typeof ticket !== 'object') {
                return;
            }

            var nomor = str(ticket.nomor_tiket).trim();
            if (nomor === '') {
                return;
            }

            var card = document.createElement('div');
            card.className = 'chat-ticket';

            var head = document.createElement('div');
            head.className = 'chat-ticket-head';

            var no = document.createElement('span');
            no.className = 'chat-ticket-no';
            no.textContent = nomor;
            head.appendChild(no);

            var badge = document.createElement('span');
            badge.className = 'status-badge ' + (STATUS_CLASS[str(ticket.status_key)] || 'status-netral');
            var dot = document.createElement('span');
            dot.className = 'status-dot';
            dot.setAttribute('aria-hidden', 'true');
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(str(ticket.status)));
            head.appendChild(badge);

            card.appendChild(head);

            addRow(card, 'Kategori', ticket.kategori);
            addRow(card, 'Pelanggan', ticket.nama_pelanggan);
            addRow(card, 'Tanggal Dibuat', ticket.dibuat);
            addRow(card, 'Teknisi', ticket.teknisi);
            if (ticket.dibatalkan) {
                addRow(card, 'Catatan', 'Dibatalkan');
            }

            wrap.appendChild(card);
        });

        if (wrap.childNodes.length > 0) {
            container.appendChild(wrap);
        }
    }

    /* --- Bubble --- */
    function createTextBubble(role, content) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--' + role;

        var body = document.createElement('div');
        body.className = 'chat-bubble-body chat-bubble-body--plain';
        body.textContent = str(content);

        wrap.appendChild(body);

        return wrap;
    }

    function createRichBubble(role, content) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--' + role;

        var body = document.createElement('div');
        body.className = 'chat-bubble-body';

        var inner = document.createElement('div');
        inner.className = 'chat-rich';
        renderRich(inner, content);
        body.appendChild(inner);

        wrap.appendChild(body);

        return wrap;
    }

    function createReplyBubble(reply, tickets, tables) {
        var list = Array.isArray(tickets) ? tickets : [];
        var structuredTables = Array.isArray(tables) ? tables : [];
        var text = str(reply);
        var wrap = document.createElement('div');
        wrap.className = 'chat-bubble chat-bubble--assistant';

        var body = document.createElement('div');
        body.className = 'chat-bubble-body';

        if (text.trim() !== '') {
            var inner = document.createElement('div');
            inner.className = 'chat-rich';
            renderRich(inner, text);
            body.appendChild(inner);
        }

        if (structuredTables.length > 0) {
            wrap.className += ' chat-bubble--tables';
            renderTables(body, structuredTables);
        }

        if (list.length > 0) {
            wrap.className += ' chat-bubble--cards';
            renderCards(body, list);
        }

        if (body.childNodes.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'chat-rich';
            var p = document.createElement('p');
            p.className = 'chat-para';
            p.textContent = '';
            empty.appendChild(p);
            body.appendChild(empty);
        }

        wrap.appendChild(body);

        return wrap;
    }

    window.WifiCareAiRender = {
        createTextBubble: createTextBubble,
        createRichBubble: createRichBubble,
        createReplyBubble: createReplyBubble,
        renderRich: renderRich,
        renderTables: renderTables
    };
})();
