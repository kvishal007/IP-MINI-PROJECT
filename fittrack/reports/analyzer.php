<?php
/**
 * File    : reports/analyzer.php
 * Purpose : THE ANALYZER — the core of the project. Every metric below is
 *           produced by real SQL aggregation (GROUP BY / COUNT / SUM / AVG /
 *           DATEDIFF / HAVING / JOIN), not by looping over tables in PHP.
 *
 *           4.1  KPI cards
 *           4.2  Attendance percentage per member + bands
 *           4.4  Dropout risk (At Risk / Likely Dropout)
 *           4.5  Peak hour analysis
 *           4.6  Weekday footfall
 *           4.7  Monthly attendance trend (6 months)
 *           4.8  Plan-wise distribution and revenue
 *           4.9  Trainer load and their members' average attendance
 *           4.10 Renewal alerts (expiring in 7 days / already expired)
 *           4.12 Filters: date range, plan, trainer, attendance band
 *           4.13 CSV export + print stylesheet
 *
 * Module  : Module 4 — Analyzer / Reports (SELECT with aggregation)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = 'Analyzer';

// ===============================================================
// 4.12 FILTERS — every filter below is bound as a parameter
// ===============================================================
$from_date  = trim($_GET['from']    ?? date('Y-m-d', strtotime('-90 days')));
$to_date    = trim($_GET['to']      ?? date('Y-m-d'));
$plan_f     = (int) ($_GET['plan']    ?? 0);
$trainer_f  = (int) ($_GET['trainer'] ?? 0);
$band_f     = trim($_GET['band']    ?? '');   // Excellent | Good | Poor | Critical
$active_tab = $_GET['tab'] ?? 'overview';

// Filters that apply to the per-member table (built on the view)
$mem_where  = [];
$mem_params = [];
if ($plan_f > 0) {
    $mem_where[]           = 'v.plan_id = :plan_id';
    $mem_params[':plan_id'] = $plan_f;
}
if ($trainer_f > 0) {
    $mem_where[]              = 'v.trainer_id = :trainer_id';
    $mem_params[':trainer_id'] = $trainer_f;
}
// Attendance band maps to a range on the computed percentage.
// HAVING is used because attendance_pct is an aggregate-derived column.
$band_having = '';
if ($band_f === 'Excellent') { $band_having = 'HAVING v.attendance_pct >= 75'; }
elseif ($band_f === 'Good')  { $band_having = 'HAVING v.attendance_pct >= 50 AND v.attendance_pct < 75'; }
elseif ($band_f === 'Poor')  { $band_having = 'HAVING v.attendance_pct >= 25 AND v.attendance_pct < 50'; }
elseif ($band_f === 'Critical') { $band_having = 'HAVING v.attendance_pct < 25'; }

$mem_where_sql = $mem_where ? 'WHERE ' . implode(' AND ', $mem_where) : '';

// Filters that apply to attendance-based charts (date range + plan/trainer)
$att_where  = ['a.att_date BETWEEN :from AND :to'];
$att_params = [':from' => $from_date, ':to' => $to_date];
if ($plan_f > 0) {
    $att_where[]            = 'm.plan_id = :plan_id';
    $att_params[':plan_id']  = $plan_f;
}
if ($trainer_f > 0) {
    $att_where[]               = 'm.trainer_id = :trainer_id';
    $att_params[':trainer_id']  = $trainer_f;
}
$att_where_sql = 'WHERE ' . implode(' AND ', $att_where);

// ===============================================================
// 4.1 KPI CARDS
// ===============================================================

// Member counts by status — SUM(condition) counts rows matching each status
$kpi = $pdo->query(
    "SELECT COUNT(*)                  AS total_members,
            SUM(status = 'Active')    AS active_members,
            SUM(status = 'Expired')   AS expired_members,
            SUM(status = 'Cancelled') AS cancelled_members
     FROM member"
)->fetch();

// Distinct members who checked in today
$present_today = (int) $pdo->query(
    'SELECT COUNT(DISTINCT member_id) FROM attendance WHERE att_date = CURDATE()'
)->fetchColumn();

// This calendar month's collections
$month_revenue = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM payment
     WHERE MONTH(paid_date) = MONTH(CURDATE())
       AND YEAR(paid_date)  = YEAR(CURDATE())"
)->fetchColumn();

// Average attendance % across every member (the view already computes each
// member's own percentage; AVG() then averages those percentages)
$avg_attendance = (float) $pdo->query(
    'SELECT COALESCE(ROUND(AVG(attendance_pct), 2), 0) FROM v_member_summary'
)->fetchColumn();

// ===============================================================
// 4.2 + 4.4 PER-MEMBER TABLE: attendance %, band, risk flag
// ===============================================================
$sql = "SELECT v.member_id, v.member_code, v.member_name, v.status,
               v.plan_name, v.trainer_name, v.join_date, v.expiry_date,
               v.attendance_pct, v.last_visit, v.days_since_visit
        FROM v_member_summary v
        $mem_where_sql
        $band_having
        ORDER BY v.attendance_pct DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($mem_params);
$member_rows = $stmt->fetchAll();

// Band distribution (counted from the same filtered set, in PHP, over an
// already-aggregated result — not a second pass over the raw tables)
$band_counts = ['Excellent' => 0, 'Good' => 0, 'Poor' => 0, 'Critical' => 0];
foreach ($member_rows as $r) {
    $band_counts[attendance_band((float) $r['attendance_pct'])['label']]++;
}

// ===============================================================
// 4.4 DROPOUT RISK — Active members with a 10+ day gap.
// DATEDIFF gives the gap; the CASE bands it; HAVING filters on the
// computed column (you cannot use a derived alias in WHERE).
// ===============================================================
$stmt = $pdo->prepare(
    "SELECT v.member_id, v.member_code, v.member_name, v.phone,
            v.plan_name, v.trainer_name, v.last_visit, v.attendance_pct,
            v.days_since_visit,
            CASE
                WHEN v.days_since_visit IS NULL OR v.days_since_visit >= 21 THEN 'Likely Dropout'
                WHEN v.days_since_visit >= 10                               THEN 'At Risk'
            END AS risk_label
     FROM v_member_summary v
     WHERE v.status = 'Active'
       AND (v.days_since_visit IS NULL OR v.days_since_visit >= 10)
     ORDER BY v.days_since_visit IS NULL DESC, v.days_since_visit DESC"
);
$stmt->execute();
$risk_rows = $stmt->fetchAll();

// ===============================================================
// 4.5 PEAK HOUR — group check-ins by the hour of day
// ===============================================================
$stmt = $pdo->prepare(
    "SELECT HOUR(a.check_in) AS hr, COUNT(*) AS visits
     FROM attendance a
     JOIN member m ON m.member_id = a.member_id
     $att_where_sql
     GROUP BY HOUR(a.check_in)
     ORDER BY hr"
);
$stmt->execute($att_params);
$peak_rows = $stmt->fetchAll();

$peak_labels = [];
$peak_data   = [];
$busiest_hour = null;
$busiest_count = 0;
foreach ($peak_rows as $r) {
    $hr = (int) $r['hr'];
    $peak_labels[] = date('g A', mktime($hr, 0, 0));
    $peak_data[]   = (int) $r['visits'];
    if ((int) $r['visits'] > $busiest_count) {
        $busiest_count = (int) $r['visits'];
        $busiest_hour  = $hr;
    }
}
// Describe the busiest hour in words, e.g. "6 AM – 7 AM"
$busiest_label = $busiest_hour === null
    ? 'No check-ins in this range'
    : date('g A', mktime($busiest_hour, 0, 0)) . ' – ' . date('g A', mktime($busiest_hour + 1, 0, 0));

// ===============================================================
// 4.6 WEEKDAY FOOTFALL — average visits per weekday.
// total visits on that weekday / number of distinct such dates.
// ===============================================================
$stmt = $pdo->prepare(
    "SELECT DAYOFWEEK(a.att_date) AS dow,
            DAYNAME(a.att_date)   AS day_name,
            COUNT(*)              AS total_visits,
            COUNT(DISTINCT a.att_date) AS day_count,
            ROUND(COUNT(*) / COUNT(DISTINCT a.att_date), 1) AS avg_footfall
     FROM attendance a
     JOIN member m ON m.member_id = a.member_id
     $att_where_sql
     GROUP BY DAYOFWEEK(a.att_date), DAYNAME(a.att_date)
     ORDER BY dow"
);
$stmt->execute($att_params);
$weekday_rows = $stmt->fetchAll();
// Reorder Mon..Sun (MySQL's DAYOFWEEK starts at Sunday = 1)
usort($weekday_rows, fn($a, $b) => (((int)$a['dow'] + 5) % 7) <=> (((int)$b['dow'] + 5) % 7));
$weekday_labels = array_map(fn($r) => substr($r['day_name'], 0, 3), $weekday_rows);
$weekday_data   = array_map(fn($r) => (float) $r['avg_footfall'], $weekday_rows);

// ===============================================================
// 4.7 MONTHLY TREND — last 6 months of check-ins.
// DATE_FORMAT is applied to MIN(att_date) so the label is an aggregate
// and therefore legal under MySQL's ONLY_FULL_GROUP_BY.
// ===============================================================
$trend_rows = $pdo->query(
    "SELECT DATE_FORMAT(MIN(att_date), '%b %Y') AS month_label,
            COUNT(*)                     AS visits,
            COUNT(DISTINCT member_id)    AS unique_members
     FROM attendance
     WHERE att_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY YEAR(att_date), MONTH(att_date)
     ORDER BY YEAR(att_date), MONTH(att_date)"
)->fetchAll();
$trend_labels = array_column($trend_rows, 'month_label');
$trend_data   = array_map('intval', array_column($trend_rows, 'visits'));

// ===============================================================
// 4.8 PLAN-WISE DISTRIBUTION AND REVENUE
// Member count and revenue are computed as separate correlated
// subqueries. Joining member AND payment in one query would fan out
// (each member row multiplied by each payment row) and inflate both.
// ===============================================================
$plan_rows = $pdo->query(
    "SELECT p.plan_id, p.plan_name, p.fee, p.duration_months,
            (SELECT COUNT(*) FROM member m WHERE m.plan_id = p.plan_id) AS member_count,
            (SELECT COUNT(*) FROM member m WHERE m.plan_id = p.plan_id AND m.status = 'Active') AS active_count,
            (SELECT COALESCE(SUM(y.amount), 0) FROM payment y WHERE y.plan_id = p.plan_id) AS revenue
     FROM plan p
     ORDER BY p.duration_months, p.fee"
)->fetchAll();
$plan_labels  = array_column($plan_rows, 'plan_name');
$plan_counts  = array_map('intval', array_column($plan_rows, 'member_count'));
$plan_revenue = array_map('floatval', array_column($plan_rows, 'revenue'));
$total_revenue = array_sum($plan_revenue);

// ===============================================================
// 4.9 TRAINER LOAD — members per trainer and their average attendance %
// ===============================================================
$trainer_rows = $pdo->query(
    "SELECT t.trainer_id, t.trainer_name, t.specialization, t.shift,
            COUNT(v.member_id)               AS member_count,
            SUM(v.status = 'Active')         AS active_count,
            ROUND(AVG(v.attendance_pct), 2)  AS avg_attendance_pct
     FROM trainer t
     LEFT JOIN v_member_summary v ON v.trainer_id = t.trainer_id
     GROUP BY t.trainer_id, t.trainer_name, t.specialization, t.shift
     ORDER BY member_count DESC, t.trainer_name"
)->fetchAll();

// ===============================================================
// 4.10 RENEWAL ALERTS — expiring within 7 days, and already expired
// ===============================================================
$expiring_rows = $pdo->query(
    "SELECT m.member_id, m.member_code, m.member_name, m.phone,
            p.plan_name, m.expiry_date,
            DATEDIFF(m.expiry_date, CURDATE()) AS days_left
     FROM member m
     JOIN plan p ON p.plan_id = m.plan_id
     WHERE m.status = 'Active'
       AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY m.expiry_date"
)->fetchAll();

$expired_rows = $pdo->query(
    "SELECT m.member_id, m.member_code, m.member_name, m.phone,
            p.plan_name, m.expiry_date,
            DATEDIFF(CURDATE(), m.expiry_date) AS days_overdue
     FROM member m
     JOIN plan p ON p.plan_id = m.plan_id
     WHERE m.status = 'Expired'
     ORDER BY m.expiry_date DESC
     LIMIT 25"
)->fetchAll();

// Filter dropdown sources
$plans_all    = $pdo->query('SELECT plan_id, plan_name FROM plan ORDER BY fee')->fetchAll();
$trainers_all = $pdo->query('SELECT trainer_id, trainer_name FROM trainer ORDER BY trainer_name')->fetchAll();

// Query string used by the CSV export links so exports match the filters
$filter_qs = http_build_query([
    'from' => $from_date, 'to' => $to_date,
    'plan' => $plan_f ?: '', 'trainer' => $trainer_f ?: '', 'band' => $band_f,
]);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">Analyzer</h2>
            <p class="text-muted mb-0">Module 4 — SELECT with aggregation · <?= fmt_date($from_date) ?> to <?= fmt_date($to_date) ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= base_url('reports/revenue.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-currency-rupee me-1"></i> Revenue Report
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="bi bi-printer-fill me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- ============ 4.12 FILTERS ============ -->
    <form method="GET" action="analyzer.php" class="filter-bar mb-4 no-print">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">From</label>
                <input type="date" class="form-control" name="from" value="<?= h($from_date) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">To</label>
                <input type="date" class="form-control" name="to" value="<?= h($to_date) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Plan</label>
                <select class="form-select" name="plan">
                    <option value="0">All Plans</option>
                    <?php foreach ($plans_all as $p): ?>
                        <option value="<?= $p['plan_id'] ?>" <?= $plan_f === (int)$p['plan_id'] ? 'selected' : '' ?>>
                            <?= h($p['plan_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Trainer</label>
                <select class="form-select" name="trainer">
                    <option value="0">All Trainers</option>
                    <?php foreach ($trainers_all as $t): ?>
                        <option value="<?= $t['trainer_id'] ?>" <?= $trainer_f === (int)$t['trainer_id'] ? 'selected' : '' ?>>
                            <?= h($t['trainer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Attendance Band</label>
                <select class="form-select" name="band">
                    <option value="">All Bands</option>
                    <?php foreach (['Excellent','Good','Poor','Critical'] as $b): ?>
                        <option value="<?= $b ?>" <?= $band_f === $b ? 'selected' : '' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Apply</button>
                <a href="analyzer.php" class="btn btn-outline-secondary flex-fill">Reset</a>
            </div>
        </div>
    </form>

    <!-- ============ 4.1 KPI CARDS ============ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-value"><?= (int)$kpi['total_members'] ?></div>
                <div class="kpi-label">Total Members</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon"><i class="bi bi-person-check-fill"></i></div>
                <div class="kpi-value"><?= (int)$kpi['active_members'] ?></div>
                <div class="kpi-label">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon"><i class="bi bi-person-x-fill"></i></div>
                <div class="kpi-value"><?= (int)$kpi['expired_members'] ?></div>
                <div class="kpi-label">Expired</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon"><i class="bi bi-calendar-check-fill"></i></div>
                <div class="kpi-value"><?= $present_today ?></div>
                <div class="kpi-label">Present Today</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card kpi-accent">
                <div class="kpi-icon"><i class="bi bi-currency-rupee"></i></div>
                <div class="kpi-value">₹<?= number_format($month_revenue, 0) ?></div>
                <div class="kpi-label">This Month</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="kpi-value"><?= $avg_attendance ?>%</div>
                <div class="kpi-label">Avg Attendance</div>
            </div>
        </div>
    </div>

    <!-- ============ CHARTS ============ -->
    <div class="row g-4 mb-4">
        <!-- 4.5 Peak hour -->
        <div class="col-12 col-xl-6">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Peak Hour Analysis</h5>
                        <p class="chart-subtitle text-muted small mb-0">
                            Busiest hour: <strong class="text-accent"><?= h($busiest_label) ?></strong>
                            <?php if ($busiest_count > 0): ?>(<?= $busiest_count ?> check-ins)<?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="chart-body">
                    <?php if (empty($peak_data)): ?>
                        <div class="empty-state py-4"><i class="bi bi-bar-chart fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No check-ins in this date range.</p></div>
                    <?php else: ?>
                        <canvas id="peakChart" height="130"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4.6 Weekday footfall -->
        <div class="col-12 col-xl-6">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Weekday Footfall</h5>
                        <p class="chart-subtitle text-muted small mb-0">Average check-ins per day of week</p>
                    </div>
                </div>
                <div class="chart-body">
                    <?php if (empty($weekday_data)): ?>
                        <div class="empty-state py-4"><i class="bi bi-bar-chart fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No check-ins in this date range.</p></div>
                    <?php else: ?>
                        <canvas id="weekdayChart" height="130"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4.7 Monthly trend -->
        <div class="col-12 col-xl-7">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Monthly Attendance Trend</h5>
                        <p class="chart-subtitle text-muted small mb-0">Total check-ins per month (last 6 months)</p>
                    </div>
                </div>
                <div class="chart-body">
                    <?php if (empty($trend_data)): ?>
                        <div class="empty-state py-4"><i class="bi bi-graph-up fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No attendance history yet.</p></div>
                    <?php else: ?>
                        <canvas id="trendChart" height="120"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4.8 Plan distribution -->
        <div class="col-12 col-xl-5">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Plan Distribution</h5>
                        <p class="chart-subtitle text-muted small mb-0">Members per plan</p>
                    </div>
                </div>
                <div class="chart-body d-flex justify-content-center">
                    <canvas id="planChart" style="max-height:250px;max-width:250px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ TABS: detailed report tables ============ -->
    <ul class="nav nav-tabs mb-0 no-print" role="tablist">
        <li class="nav-item"><button class="nav-link <?= $active_tab === 'overview' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-members" type="button">
            <i class="bi bi-people-fill me-1"></i> Attendance % (<?= count($member_rows) ?>)</button></li>
        <li class="nav-item"><button class="nav-link <?= $active_tab === 'dropout' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-dropout" type="button">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Dropout Risk (<?= count($risk_rows) ?>)</button></li>
        <li class="nav-item"><button class="nav-link <?= $active_tab === 'renewals' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-renewals" type="button">
            <i class="bi bi-alarm-fill me-1"></i> Renewal Alerts (<?= count($expiring_rows) + count($expired_rows) ?>)</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-plans" type="button">
            <i class="bi bi-card-list me-1"></i> Plans</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-trainers" type="button">
            <i class="bi bi-person-badge-fill me-1"></i> Trainers</button></li>
    </ul>

    <div class="tab-content table-card p-0">
        <!-- ---------- 4.2 Attendance % per member ---------- -->
        <div class="tab-pane fade <?= $active_tab === 'overview' ? 'show active' : '' ?>" id="tab-members">
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom flex-wrap gap-2">
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach ($band_counts as $label => $count):
                        $cls = attendance_band($label === 'Excellent' ? 80 : ($label === 'Good' ? 60 : ($label === 'Poor' ? 30 : 10)))['class']; ?>
                        <span class="badge bg-<?= $cls ?>"><?= $label ?>: <?= $count ?></span>
                    <?php endforeach; ?>
                </div>
                <a href="<?= base_url('reports/export_csv.php?report=attendance_pct&' . $filter_qs) ?>"
                   class="btn btn-sm btn-outline-success no-print">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                </a>
            </div>
            <?php if (empty($member_rows)): ?>
                <div class="empty-state py-5"><i class="bi bi-people fs-1 text-muted"></i>
                    <h5 class="mt-3">No members match these filters</h5></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head">
                        <tr><th>Code</th><th>Member</th><th>Plan</th><th>Trainer</th>
                            <th>Attendance %</th><th>Band</th><th>Last Visit</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($member_rows as $r):
                        $pct = (float) $r['attendance_pct']; $band = attendance_band($pct); ?>
                        <tr>
                            <td><code><?= h($r['member_code']) ?></code></td>
                            <td><a href="<?= base_url('members/view.php?id=' . $r['member_id']) ?>" class="text-decoration-none fw-semibold"><?= h($r['member_name']) ?></a></td>
                            <td><?= h($r['plan_name']) ?></td>
                            <td><?= h($r['trainer_name'] ?? '—') ?></td>
                            <td style="min-width:140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-fill" style="height:6px;">
                                        <div class="progress-bar bg-<?= $band['class'] ?>" style="width:<?= min(100, $pct) ?>%"></div>
                                    </div>
                                    <span class="small fw-semibold"><?= number_format($pct, 2) ?>%</span>
                                </div>
                            </td>
                            <td><span class="badge bg-<?= $band['class'] ?>"><?= $band['label'] ?></span></td>
                            <td><?= fmt_date($r['last_visit']) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td class="no-print">
                                <a href="<?= base_url('reports/member_report.php?id=' . $r['member_id']) ?>"
                                   class="btn btn-xs btn-outline-primary" title="Report card">
                                    <i class="bi bi-file-earmark-bar-graph"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ---------- 4.4 Dropout risk ---------- -->
        <div class="tab-pane fade <?= $active_tab === 'dropout' ? 'show active' : '' ?>" id="tab-dropout">
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                <p class="mb-0 text-muted small">
                    Active members with no visit for 10+ days (At Risk) or 21+ days / never (Likely Dropout).
                </p>
                <a href="<?= base_url('reports/export_csv.php?report=dropout_risk&' . $filter_qs) ?>"
                   class="btn btn-sm btn-outline-success no-print">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                </a>
            </div>
            <?php if (empty($risk_rows)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-shield-check fs-1 text-success"></i>
                    <h5 class="mt-3">No members at risk — good retention this week!</h5>
                    <p class="text-muted">Every active member has visited within the last 10 days.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head">
                        <tr><th>Code</th><th>Member</th><th>Phone</th><th>Plan</th><th>Trainer</th>
                            <th>Last Visit</th><th>Days Absent</th><th>Attendance %</th><th>Risk</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($risk_rows as $r): ?>
                        <tr>
                            <td><code><?= h($r['member_code']) ?></code></td>
                            <td><a href="<?= base_url('members/view.php?id=' . $r['member_id']) ?>" class="text-decoration-none fw-semibold"><?= h($r['member_name']) ?></a></td>
                            <td><?= h($r['phone']) ?></td>
                            <td><?= h($r['plan_name']) ?></td>
                            <td><?= h($r['trainer_name'] ?? '—') ?></td>
                            <td><?= $r['last_visit'] ? fmt_date($r['last_visit']) : '<span class="text-danger">Never visited</span>' ?></td>
                            <td><strong><?= $r['days_since_visit'] !== null ? (int)$r['days_since_visit'] : '—' ?></strong></td>
                            <td><?= number_format((float)$r['attendance_pct'], 2) ?>%</td>
                            <td>
                                <span class="badge bg-<?= $r['risk_label'] === 'Likely Dropout' ? 'danger' : 'warning text-dark' ?>">
                                    <?= h($r['risk_label']) ?>
                                </span>
                            </td>
                            <td class="no-print">
                                <a href="tel:<?= h($r['phone']) ?>" class="btn btn-xs btn-outline-success" title="Call member">
                                    <i class="bi bi-telephone-fill"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ---------- 4.10 Renewal alerts ---------- -->
        <div class="tab-pane fade <?= $active_tab === 'renewals' ? 'show active' : '' ?>" id="tab-renewals">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-alarm-fill text-warning me-2"></i>Expiring in the next 7 days</h6>
                <a href="<?= base_url('reports/export_csv.php?report=renewals&' . $filter_qs) ?>"
                   class="btn btn-sm btn-outline-success no-print">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                </a>
            </div>
            <?php if (empty($expiring_rows)): ?>
                <div class="empty-state py-4"><i class="bi bi-check-circle-fill fs-2 text-success"></i>
                    <p class="mt-2 mb-0">No memberships expire in the next 7 days.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head"><tr><th>Code</th><th>Member</th><th>Phone</th><th>Plan</th><th>Expiry</th><th>Days Left</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($expiring_rows as $r): ?>
                        <tr>
                            <td><code><?= h($r['member_code']) ?></code></td>
                            <td><?= h($r['member_name']) ?></td>
                            <td><?= h($r['phone']) ?></td>
                            <td><?= h($r['plan_name']) ?></td>
                            <td><?= fmt_date($r['expiry_date']) ?></td>
                            <td><span class="badge bg-<?= (int)$r['days_left'] <= 2 ? 'danger' : 'warning text-dark' ?>"><?= (int)$r['days_left'] ?> days</span></td>
                            <td class="no-print">
                                <a href="<?= base_url("members/edit.php?id={$r['member_id']}&tab=renew") ?>" class="btn btn-xs btn-primary">Renew</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="p-3 border-bottom border-top mt-3">
                <h6 class="mb-0"><i class="bi bi-x-octagon-fill text-danger me-2"></i>Already expired</h6>
            </div>
            <?php if (empty($expired_rows)): ?>
                <div class="empty-state py-4"><i class="bi bi-check-circle-fill fs-2 text-success"></i>
                    <p class="mt-2 mb-0">No expired memberships. Every member is up to date.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head"><tr><th>Code</th><th>Member</th><th>Phone</th><th>Plan</th><th>Expired On</th><th>Days Overdue</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($expired_rows as $r): ?>
                        <tr>
                            <td><code><?= h($r['member_code']) ?></code></td>
                            <td><?= h($r['member_name']) ?></td>
                            <td><?= h($r['phone']) ?></td>
                            <td><?= h($r['plan_name']) ?></td>
                            <td><?= fmt_date($r['expiry_date']) ?></td>
                            <td><span class="badge bg-secondary"><?= (int)$r['days_overdue'] ?> days</span></td>
                            <td class="no-print">
                                <a href="<?= base_url("members/edit.php?id={$r['member_id']}&tab=renew") ?>" class="btn btn-xs btn-primary">Renew</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ---------- 4.8 Plan table ---------- -->
        <div class="tab-pane fade" id="tab-plans">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head">
                        <tr><th>Plan</th><th>Duration</th><th>Fee</th><th>Members</th><th>Active</th>
                            <th>Revenue</th><th>Share of Revenue</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plan_rows as $p):
                        $share = $total_revenue > 0 ? ($p['revenue'] / $total_revenue) * 100 : 0; ?>
                        <tr>
                            <td><strong><?= h($p['plan_name']) ?></strong></td>
                            <td><?= (int)$p['duration_months'] ?> mo</td>
                            <td>₹<?= number_format($p['fee'], 0) ?></td>
                            <td><?= (int)$p['member_count'] ?></td>
                            <td><?= (int)$p['active_count'] ?></td>
                            <td>₹<?= number_format($p['revenue'], 0) ?></td>
                            <td style="min-width:140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-fill" style="height:6px;">
                                        <div class="progress-bar bg-accent" style="width:<?= round($share, 1) ?>%"></div>
                                    </div>
                                    <span class="small"><?= number_format($share, 1) ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr><td colspan="5" class="text-end">Total Revenue</td>
                            <td>₹<?= number_format($total_revenue, 0) ?></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ---------- 4.9 Trainer load ---------- -->
        <div class="tab-pane fade" id="tab-trainers">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head">
                        <tr><th>Trainer</th><th>Specialization</th><th>Shift</th><th>Members</th>
                            <th>Active</th><th>Avg Attendance % of their members</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($trainer_rows as $t):
                        $avg  = $t['avg_attendance_pct'] !== null ? (float)$t['avg_attendance_pct'] : null;
                        $band = $avg !== null ? attendance_band($avg) : null; ?>
                        <tr>
                            <td><strong><?= h($t['trainer_name']) ?></strong></td>
                            <td><span class="badge bg-secondary"><?= h($t['specialization']) ?></span></td>
                            <td><?= h($t['shift']) ?></td>
                            <td><?= (int)$t['member_count'] ?></td>
                            <td><?= (int)$t['active_count'] ?></td>
                            <td style="min-width:180px;">
                                <?php if ($avg !== null): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-fill" style="height:6px;">
                                            <div class="progress-bar bg-<?= $band['class'] ?>" style="width:<?= min(100, $avg) ?>%"></div>
                                        </div>
                                        <span class="small fw-semibold"><?= $avg ?>%</span>
                                        <span class="badge bg-<?= $band['class'] ?>"><?= $band['label'] ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No members assigned</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart data injected as JSON for assets/js/script.js -->
<script>
window.FT = window.FT || {};
window.FT.peakLabels   = <?= json_encode($peak_labels) ?>;
window.FT.peakData     = <?= json_encode($peak_data) ?>;
window.FT.wdLabels     = <?= json_encode($weekday_labels) ?>;
window.FT.wdData       = <?= json_encode($weekday_data) ?>;
window.FT.trendLabels  = <?= json_encode($trend_labels) ?>;
window.FT.trendData    = <?= json_encode($trend_data) ?>;
window.FT.planLabels   = <?= json_encode($plan_labels) ?>;
window.FT.planData     = <?= json_encode($plan_counts) ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
