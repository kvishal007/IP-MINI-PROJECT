<?php
/**
 * File    : index.php
 * Purpose : Dashboard — KPI cards, 4 live charts, renewal alerts, dropout risk summary.
 * Module  : Module 4 (Analyzer/Reports) — Dashboard view
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_db();
$page_title = 'Dashboard';

// ---------------------------------------------------------------
// KPI 1: Total members, active, expired, present today
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status='Active')    AS active_count,
        SUM(status='Expired')   AS expired_count,
        SUM(status='Cancelled') AS cancelled_count
     FROM member"
);
$kpi_members = $stmt->fetch();

$stmt = $pdo->query(
    "SELECT COUNT(DISTINCT member_id) AS present_today
     FROM attendance WHERE att_date = CURDATE()"
);
$kpi_today = $stmt->fetchColumn();

// ---------------------------------------------------------------
// KPI 2: This month's revenue
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) AS monthly_revenue
     FROM payment
     WHERE MONTH(paid_date) = MONTH(CURDATE())
       AND YEAR(paid_date)  = YEAR(CURDATE())"
);
$monthly_revenue = $stmt->fetchColumn();

// ---------------------------------------------------------------
// KPI 3: Average attendance percentage across all active members
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT ROUND(AVG(attendance_pct), 1) AS avg_pct
     FROM v_member_summary
     WHERE status = 'Active'"
);
$avg_pct = $stmt->fetchColumn() ?: 0;

// ---------------------------------------------------------------
// Chart 1: Monthly attendance trend — last 6 months
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT DATE_FORMAT(MIN(att_date), '%b %Y') AS month_label,
            COUNT(*) AS visits
     FROM attendance
     WHERE att_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY YEAR(att_date), MONTH(att_date)
     ORDER BY YEAR(att_date), MONTH(att_date)"
);
$monthly_trend = $stmt->fetchAll();
$trend_labels  = array_column($monthly_trend, 'month_label');
$trend_data    = array_column($monthly_trend, 'visits');

// ---------------------------------------------------------------
// Chart 2: Peak hour distribution
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT HOUR(check_in) AS hr, COUNT(*) AS cnt
     FROM attendance
     GROUP BY HOUR(check_in)
     ORDER BY hr"
);
$peak_rows   = $stmt->fetchAll();
$peak_labels = [];
$peak_data   = [];
foreach ($peak_rows as $r) {
    $h_num = (int) $r['hr'];
    $peak_labels[] = sprintf('%02d:00', $h_num);
    $peak_data[]   = (int) $r['cnt'];
}

// ---------------------------------------------------------------
// Chart 3: Plan-wise member distribution
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT p.plan_name, COUNT(m.member_id) AS cnt
     FROM plan p
     LEFT JOIN member m ON m.plan_id = p.plan_id
     GROUP BY p.plan_id, p.plan_name
     ORDER BY cnt DESC"
);
$plan_dist   = $stmt->fetchAll();
$plan_labels = array_column($plan_dist, 'plan_name');
$plan_data   = array_column($plan_dist, 'cnt');

// ---------------------------------------------------------------
// Chart 4: Weekday average footfall
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT DAYNAME(att_date) AS day_name,
            DAYOFWEEK(att_date) AS dow_num,
            COUNT(*) AS total_visits,
            COUNT(DISTINCT att_date) AS total_days,
            ROUND(COUNT(*) / COUNT(DISTINCT att_date), 1) AS avg_visits
     FROM attendance
     WHERE att_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
     GROUP BY DAYOFWEEK(att_date), DAYNAME(att_date)
     ORDER BY dow_num"
);
$weekday_rows   = $stmt->fetchAll();
$weekday_labels = array_column($weekday_rows, 'day_name');
$weekday_data   = array_column($weekday_rows, 'avg_visits');

// ---------------------------------------------------------------
// Renewal Alerts — expiring in 7 days
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT m.member_code, m.member_name, m.phone,
            p.plan_name, m.expiry_date,
            DATEDIFF(m.expiry_date, CURDATE()) AS days_left
     FROM member m
     JOIN plan p ON m.plan_id = p.plan_id
     WHERE m.status = 'Active'
       AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY m.expiry_date ASC
     LIMIT 10"
);
$stmt->execute();
$expiring_soon = $stmt->fetchAll();

// ---------------------------------------------------------------
// Dropout Risk Summary
// ---------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT member_name, member_code,
            days_since_visit,
            CASE
                WHEN days_since_visit >= 21 THEN 'Likely Dropout'
                WHEN days_since_visit >= 10 THEN 'At Risk'
            END AS risk_label
     FROM v_member_summary
     WHERE status = 'Active'
       AND days_since_visit >= 10
     ORDER BY days_since_visit DESC
     LIMIT 5"
);
$risk_members = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-4 py-3">

    <!-- --------------------------------------------------------
         KPI CARDS
    --------------------------------------------------------- -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-value"><?= h($kpi_members['total']) ?></div>
                <div class="kpi-label">Total Members</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon"><i class="bi bi-person-check-fill"></i></div>
                <div class="kpi-value"><?= h($kpi_members['active_count']) ?></div>
                <div class="kpi-label">Active</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon"><i class="bi bi-person-x-fill"></i></div>
                <div class="kpi-value"><?= h($kpi_members['expired_count']) ?></div>
                <div class="kpi-label">Expired</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon"><i class="bi bi-calendar-check-fill"></i></div>
                <div class="kpi-value"><?= h($kpi_today) ?></div>
                <div class="kpi-label">Present Today</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="kpi-card kpi-accent">
                <div class="kpi-icon"><i class="bi bi-currency-rupee"></i></div>
                <div class="kpi-value">₹<?= number_format((float)$monthly_revenue, 0) ?></div>
                <div class="kpi-label">This Month Revenue</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="kpi-value"><?= h($avg_pct) ?>%</div>
                <div class="kpi-label">Avg Attendance</div>
            </div>
        </div>
    </div>

    <!-- --------------------------------------------------------
         CHARTS ROW 1: Monthly Trend + Peak Hour
    --------------------------------------------------------- -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-7">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Monthly Attendance Trend</h5>
                        <p class="chart-subtitle text-muted small">Total check-ins per month (last 6 months)</p>
                    </div>
                    <a href="<?= base_url('reports/analyzer.php') ?>" class="btn btn-sm btn-outline-primary">Full Report</a>
                </div>
                <div class="chart-body">
                    <canvas id="trendChart" height="100"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Peak Hour Analysis</h5>
                        <p class="chart-subtitle text-muted small">Check-ins by hour of day</p>
                    </div>
                </div>
                <div class="chart-body">
                    <canvas id="peakChart" height="120"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- --------------------------------------------------------
         CHARTS ROW 2: Plan Distribution + Weekday Footfall
    --------------------------------------------------------- -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-5">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Plan Distribution</h5>
                        <p class="chart-subtitle text-muted small">Members enrolled per plan</p>
                    </div>
                </div>
                <div class="chart-body d-flex justify-content-center">
                    <canvas id="planChart" style="max-height:260px;max-width:260px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Weekday Footfall</h5>
                        <p class="chart-subtitle text-muted small">Avg check-ins per weekday (last 90 days)</p>
                    </div>
                </div>
                <div class="chart-body">
                    <canvas id="weekdayChart" height="110"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- --------------------------------------------------------
         ALERTS ROW: Renewals + Dropout Risk
    --------------------------------------------------------- -->
    <div class="row g-4 mb-4">
        <!-- Renewal Alerts -->
        <div class="col-12 col-xl-6">
            <div class="chart-card alert-panel">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title"><i class="bi bi-alarm-fill text-warning me-2"></i>Renewal Alerts</h5>
                        <p class="chart-subtitle text-muted small">Memberships expiring in the next 7 days</p>
                    </div>
                    <a href="<?= base_url('reports/analyzer.php?tab=renewals') ?>" class="btn btn-sm btn-outline-warning">View All</a>
                </div>
                <?php if (empty($expiring_soon)): ?>
                    <div class="empty-state">
                        <i class="bi bi-check-circle-fill text-success fs-2"></i>
                        <p class="mt-2 mb-0">No renewals due in the next 7 days.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Plan</th>
                                    <th>Expiry</th>
                                    <th>Days Left</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiring_soon as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($r['member_name']) ?></strong><br>
                                        <small class="text-muted"><?= h($r['member_code']) ?></small>
                                    </td>
                                    <td><?= h($r['plan_name']) ?></td>
                                    <td><?= fmt_date($r['expiry_date']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $r['days_left'] <= 2 ? 'danger' : 'warning text-dark' ?>">
                                            <?= h($r['days_left']) ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?= base_url('members/edit.php?code=') . h($r['member_code']) ?>&tab=renew"
                                           class="btn btn-xs btn-primary">Renew</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dropout Risk -->
        <div class="col-12 col-xl-6">
            <div class="chart-card alert-panel">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Dropout Risk</h5>
                        <p class="chart-subtitle text-muted small">Active members with no visit in 10+ days</p>
                    </div>
                    <a href="<?= base_url('reports/analyzer.php?tab=dropout') ?>" class="btn btn-sm btn-outline-danger">View All</a>
                </div>
                <?php if (empty($risk_members)): ?>
                    <div class="empty-state">
                        <i class="bi bi-shield-check text-success fs-2"></i>
                        <p class="mt-2 mb-0">No members at risk — good retention this week!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Last Visit</th>
                                    <th>Days Absent</th>
                                    <th>Risk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($risk_members as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($r['member_name']) ?></strong><br>
                                        <small class="text-muted"><?= h($r['member_code']) ?></small>
                                    </td>
                                    <td><?= h($r['days_since_visit'] !== null ? $r['days_since_visit'] . ' days ago' : 'Never') ?></td>
                                    <td><?= h($r['days_since_visit'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge bg-<?= $r['risk_label'] === 'Likely Dropout' ? 'danger' : 'warning text-dark' ?>">
                                            <?= h($r['risk_label']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Inject chart data as JSON for script.js -->
<script>
window.FT = window.FT || {};
window.FT.trendLabels  = <?= json_encode($trend_labels)   ?>;
window.FT.trendData    = <?= json_encode($trend_data)     ?>;
window.FT.peakLabels   = <?= json_encode($peak_labels)    ?>;
window.FT.peakData     = <?= json_encode($peak_data)      ?>;
window.FT.planLabels   = <?= json_encode($plan_labels)    ?>;
window.FT.planData     = <?= json_encode($plan_data)      ?>;
window.FT.wdLabels     = <?= json_encode($weekday_labels) ?>;
window.FT.wdData       = <?= json_encode($weekday_data)   ?>;
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
