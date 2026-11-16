/**
 * WiFiCare CDN — grafik dashboard.
 *
 * Membaca data grafik dari elemen <script type="application/json"
 * id="wificare-chart-data"> yang dicetak server, lalu merender grafik dengan
 * Chart.js. Tidak ada data statistik yang ditulis di sisi klien.
 *
 * Warna grafik mengikuti token tema aktif (Light/Dark) dan diperbarui saat
 * pengguna mengganti tema (event `wificare:themechange`), sehingga grafik tetap
 * terbaca pada kedua tema tanpa memuat ulang halaman.
 *
 * Canvas yang didukung:
 * - #chartStatus      : doughnut distribusi status pengaduan.
 * - #chartTrend       : line tren pengaduan harian.
 * - #chartTaskStatus  : doughnut distribusi status tugas teknisi.
 */
(function () {
    'use strict';

    var PALETTE = {
        text: '#94a3b8',
        grid: 'rgba(148, 163, 184, 0.14)',
        accent: '#2563eb',
        accentFill: 'rgba(37, 99, 235, 0.18)',
        panel: '#ffffff'
    };

    var charts = [];

    function cssVar(name, fallback) {
        var value = window.getComputedStyle(document.documentElement).getPropertyValue(name);
        value = (value || '').trim();

        return value === '' ? fallback : value;
    }

    function readPalette() {
        PALETTE.text = cssVar('--wc-secondary', PALETTE.text);
        PALETTE.grid = cssVar('--wc-border', PALETTE.grid);
        PALETTE.accent = cssVar('--wc-highlight', PALETTE.accent);
        PALETTE.panel = cssVar('--wc-panel', PALETTE.panel);
    }

    function readPayload() {
        var element = document.getElementById('wificare-chart-data');
        if (!element) {
            return null;
        }

        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            return null;
        }
    }

    function hasValues(values) {
        if (!Array.isArray(values)) {
            return false;
        }

        return values.some(function (value) {
            return Number(value) > 0;
        });
    }

    function renderDoughnut(canvasId, data) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data || !hasValues(data.values)) {
            return;
        }

        var chartGrid = canvas.closest('.report-charts');
        var wideReportChart = chartGrid !== null && chartGrid.classList.contains('report-charts--single');
        var sideLegend = wideReportChart && window.matchMedia('(min-width: 768px)').matches;

        charts.push(new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: data.colors,
                    borderColor: PALETTE.panel,
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                layout: {
                    padding: sideLegend ? { right: 8 } : 0
                },
                plugins: {
                    legend: {
                        position: sideLegend ? 'right' : 'bottom',
                        align: 'center',
                        labels: {
                            color: PALETTE.text,
                            boxWidth: sideLegend ? 10 : 12,
                            boxHeight: sideLegend ? 10 : 12,
                            padding: sideLegend ? 16 : 12,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                }
            }
        }));
    }

    function renderTrend(canvasId, data) {
        renderLine(canvasId, data, 'Pengaduan');
    }

    function renderLine(canvasId, data, label) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data) {
            return;
        }

        charts.push(new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: label || 'Nilai',
                    data: data.values,
                    borderColor: PALETTE.accent,
                    backgroundColor: PALETTE.accentFill,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: PALETTE.accent,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: PALETTE.text, maxRotation: 0, autoSkipPadding: 12 },
                        grid: { color: PALETTE.grid }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: PALETTE.text, precision: 0 },
                        grid: { color: PALETTE.grid }
                    }
                }
            }
        }));
    }

    function renderBar(canvasId, data, label) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data || !hasValues(data.values)) {
            return;
        }

        charts.push(new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: label || 'Jumlah',
                    data: data.values,
                    backgroundColor: PALETTE.accentFill,
                    borderColor: PALETTE.accent,
                    borderWidth: 1.5,
                    borderRadius: 4,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: PALETTE.text, maxRotation: 0, autoSkip: true },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: PALETTE.text, precision: 0 },
                        grid: { color: PALETTE.grid }
                    }
                }
            }
        }));
    }

    function destroyCharts() {
        charts.forEach(function (chart) {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });
        charts = [];
    }

    function renderAll(payload) {
        window.Chart.defaults.color = PALETTE.text;
        window.Chart.defaults.font.family = "'Segoe UI', system-ui, -apple-system, sans-serif";

        renderDoughnut('chartStatus', payload.status);
        renderTrend('chartTrend', payload.trend);
        renderDoughnut('chartTaskStatus', payload.task);
        renderBar('chartKategori', payload.kategori, 'Jumlah tiket');
        renderBar('chartTeknisi', payload.teknisi, 'Jumlah tugas');
        renderBar('chartRatingDist', payload.ratingDist, 'Jumlah penilaian');
        renderLine('chartRatingTren', payload.ratingTren, 'Rata-rata rating');
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.Chart === 'undefined') {
            return;
        }

        var payload = readPayload();
        if (!payload) {
            return;
        }

        readPalette();
        renderAll(payload);

        // Perbarui warna grafik saat tema berganti (tanpa muat ulang halaman).
        window.addEventListener('wificare:themechange', function () {
            readPalette();
            destroyCharts();
            renderAll(payload);
        });
    });
})();
