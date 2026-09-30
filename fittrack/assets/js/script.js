/* =============================================================
   File    : assets/js/script.js
   Purpose : Single script file for the whole application —
             sidebar toggle, client-side form validation helpers,
             table search/sort, and every Chart.js chart.
   Module  : All Modules (client side)
   Author  : Pavithran
   Course  : CS2307 - Internet Programming Laboratory

   Chart data is injected by each PHP page into window.FT as JSON.
   Every chart below draws only if its canvas AND its data exist,
   so one script file can serve pages that show different charts.
   ============================================================= */

(function () {
    'use strict';

    // Data handed over from PHP (json_encode) — default to empty
    var FT = window.FT || {};

    /* ---------------------------------------------------------
       1. SHARED CHART THEME
       --------------------------------------------------------- */
    var COLORS = {
        accent:  '#f97316',
        blue:    '#2563eb',
        green:   '#16a34a',
        amber:   '#d97706',
        purple:  '#7c3aed',
        cyan:    '#0891b2',
        grey:    '#94a3b8',
        gridline: '#eef0f3',
        text:    '#6b7280'
    };

    // Categorical palette for pie/doughnut slices
    var CATEGORICAL = [
        '#f97316', '#2563eb', '#16a34a', '#7c3aed', '#0891b2', '#d97706', '#94a3b8'
    ];

    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = "'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = COLORS.text;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, .92)';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
        Chart.defaults.maintainAspectRatio = false;
    }

    // Axis styling shared by the bar / line charts
    function cartesianScales(yTitle) {
        return {
            y: {
                beginAtZero: true,
                title: yTitle ? { display: true, text: yTitle, color: COLORS.text } : { display: false },
                grid: { color: COLORS.gridline, drawBorder: false },
                ticks: { precision: 0 }
            },
            x: {
                grid: { display: false, drawBorder: false }
            }
        };
    }

    /**
     * Draw a chart only when the canvas element and data are both present.
     * @param {string} canvasId
     * @param {function} builder returns a Chart.js config object
     * @param {Array} data the series the chart needs
     */
    function drawChart(canvasId, data, builder) {
        var el = document.getElementById(canvasId);
        if (!el || typeof Chart === 'undefined') { return; }
        if (!data || !data.length) { return; }
        new Chart(el, builder());
    }

    /* ---------------------------------------------------------
       2. MONTHLY ATTENDANCE TREND (line) — dashboard + analyzer
       --------------------------------------------------------- */
    drawChart('trendChart', FT.trendData, function () {
        return {
            type: 'line',
            data: {
                labels: FT.trendLabels || [],
                datasets: [{
                    label: 'Check-ins',
                    data: FT.trendData,
                    borderColor: COLORS.accent,
                    backgroundColor: 'rgba(249, 115, 22, .12)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: .35,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: COLORS.accent,
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: cartesianScales('Visits')
            }
        };
    });

    /* ---------------------------------------------------------
       3. PEAK HOUR (bar) — dashboard + analyzer
       The busiest bar is highlighted in accent, the rest are muted,
       so the peak reads instantly.
       --------------------------------------------------------- */
    drawChart('peakChart', FT.peakData, function () {
        var max = Math.max.apply(null, FT.peakData);
        var colors = FT.peakData.map(function (v) {
            return v === max ? COLORS.accent : 'rgba(249, 115, 22, .35)';
        });
        return {
            type: 'bar',
            data: {
                labels: FT.peakLabels || [],
                datasets: [{
                    label: 'Check-ins',
                    data: FT.peakData,
                    backgroundColor: colors,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 44
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.parsed.y + ' check-ins'; }
                        }
                    }
                },
                scales: cartesianScales('Check-ins')
            }
        };
    });

    /* ---------------------------------------------------------
       4. WEEKDAY FOOTFALL (bar) — dashboard + analyzer
       Weekend bars use a distinct muted colour.
       --------------------------------------------------------- */
    drawChart('weekdayChart', FT.wdData, function () {
        var labels = FT.wdLabels || [];
        var colors = labels.map(function (d) {
            return (d === 'Sat' || d === 'Sun') ? COLORS.grey : COLORS.blue;
        });
        return {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Avg check-ins',
                    data: FT.wdData,
                    backgroundColor: colors,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 46
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.parsed.y + ' avg check-ins'; }
                        }
                    }
                },
                scales: cartesianScales('Avg per day')
            }
        };
    });

    /* ---------------------------------------------------------
       5. PLAN DISTRIBUTION (doughnut) — dashboard + analyzer
       --------------------------------------------------------- */
    drawChart('planChart', FT.planData, function () {
        return {
            type: 'doughnut',
            data: {
                labels: FT.planLabels || [],
                datasets: [{
                    data: FT.planData,
                    backgroundColor: CATEGORICAL,
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                cutout: '58%',
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' members (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        };
    });

    /* ---------------------------------------------------------
       6. MONTHLY REVENUE (bar) — revenue report
       --------------------------------------------------------- */
    drawChart('revenueChart', FT.revData, function () {
        return {
            type: 'bar',
            data: {
                labels: FT.revLabels || [],
                datasets: [{
                    label: 'Revenue',
                    data: FT.revData,
                    backgroundColor: COLORS.green,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 52
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return '₹' + ctx.parsed.y.toLocaleString('en-IN');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: COLORS.gridline, drawBorder: false },
                        ticks: {
                            callback: function (v) { return '₹' + v.toLocaleString('en-IN'); }
                        }
                    },
                    x: { grid: { display: false, drawBorder: false } }
                }
            }
        };
    });

    /* ---------------------------------------------------------
       7. PLAN-WISE REVENUE (doughnut) — revenue report
       --------------------------------------------------------- */
    drawChart('planRevenueChart', FT.planRevData, function () {
        return {
            type: 'doughnut',
            data: {
                labels: FT.planRevLabels || [],
                datasets: [{
                    data: FT.planRevData,
                    backgroundColor: CATEGORICAL,
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                cutout: '58%',
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ₹' + ctx.parsed.toLocaleString('en-IN') + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        };
    });

    /* ---------------------------------------------------------
       8. SIDEBAR TOGGLE (mobile)
       --------------------------------------------------------- */
    var toggle  = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            document.body.classList.toggle('sidebar-open');
        });

        // Tapping the dimmed backdrop closes the sidebar
        document.addEventListener('click', function (e) {
            if (!sidebar.classList.contains('open')) { return; }
            if (sidebar.contains(e.target) || toggle.contains(e.target)) { return; }
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
        });

        // Escape closes it too (keyboard accessibility)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                document.body.classList.remove('sidebar-open');
            }
        });
    }

    /* ---------------------------------------------------------
       9. CLIENT-SIDE CONVENIENCE VALIDATION
       Server-side validation in PHP is the real gate — this only
       gives faster feedback before the round trip.
       --------------------------------------------------------- */

    // Restrict phone-style inputs to 10 digits as the user types
    document.querySelectorAll('input[pattern="\\d{10}"]').forEach(function (input) {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });
    });

    // Mark required fields that were left empty on submit
    document.querySelectorAll('form[novalidate]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var firstInvalid = null;
            form.querySelectorAll('[required]').forEach(function (field) {
                var empty = !field.value || !field.value.trim();
                field.classList.toggle('is-invalid', empty);
                if (empty && !firstInvalid) { firstInvalid = field; }
            });
            if (firstInvalid) {
                e.preventDefault();
                firstInvalid.focus();
            }
        });
    });

    /* ---------------------------------------------------------
       10. TABLE SEARCH AND COLUMN SORT
       Any table with class .table inside a .table-card gets
       click-to-sort headers. Purely presentational — the server
       still does the authoritative filtering and pagination.
       --------------------------------------------------------- */
    document.querySelectorAll('.table-card table thead th').forEach(function (th, index) {
        // Skip action / icon columns (no text label)
        if (!th.textContent.trim()) { return; }

        th.style.cursor = 'pointer';
        th.title = 'Click to sort by ' + th.textContent.trim();
        th.setAttribute('tabindex', '0');

        function sortByThisColumn() {
            var table = th.closest('table');
            var tbody = table.querySelector('tbody');
            if (!tbody) { return; }

            var asc  = th.dataset.sortDir !== 'asc';
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));

            rows.sort(function (a, b) {
                var ca = a.children[index] ? a.children[index].textContent.trim() : '';
                var cb = b.children[index] ? b.children[index].textContent.trim() : '';

                // Compare as numbers when both look numeric (₹, %, commas stripped)
                var na = parseFloat(ca.replace(/[₹,%\s]/g, ''));
                var nb = parseFloat(cb.replace(/[₹,%\s]/g, ''));
                if (!isNaN(na) && !isNaN(nb)) {
                    return asc ? na - nb : nb - na;
                }
                return asc ? ca.localeCompare(cb) : cb.localeCompare(ca);
            });

            rows.forEach(function (r) { tbody.appendChild(r); });

            // Remember direction, and clear the arrow on sibling headers
            th.parentNode.querySelectorAll('th').forEach(function (other) {
                if (other !== th) {
                    delete other.dataset.sortDir;
                    var mark = other.querySelector('.sort-mark');
                    if (mark) { mark.remove(); }
                }
            });
            th.dataset.sortDir = asc ? 'asc' : 'desc';

            var existing = th.querySelector('.sort-mark');
            if (existing) { existing.remove(); }
            var arrow = document.createElement('span');
            arrow.className = 'sort-mark';
            arrow.textContent = asc ? ' ▲' : ' ▼';
            th.appendChild(arrow);
        }

        th.addEventListener('click', sortByThisColumn);
        th.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                sortByThisColumn();
            }
        });
    });

    // Live client-side filter for any input#searchInput over its table
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function () {
            var term  = this.value.toLowerCase();
            var table = document.querySelector('.table-card table tbody');
            if (!table) { return; }
            table.querySelectorAll('tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
            });
        });
    }

    /* ---------------------------------------------------------
       11. AUTO-DISMISS SUCCESS ALERTS after 6 seconds
       --------------------------------------------------------- */
    document.querySelectorAll('.alert-success.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                var instance = bootstrap.Alert.getOrCreateInstance(alert);
                if (instance) { instance.close(); }
            }
        }, 6000);
    });

}());
