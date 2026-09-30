<?php
/**
 * File    : reports/member_report.php
 * Purpose : Individual member report card (Module 4.11) — profile, plan,
 *           payment history, a 30-day attendance heat-strip, attendance
 *           percentage with its band, current/longest streak, and the
 *           dropout risk flag. Print-friendly.
 * Module  : Module 4 — Analyzer / Reports (per-member drill-down)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo       = get_db();
$member_id = (int) ($_GET['id'] ?? 0);

if ($member_id <= 0) {
    flash('error', 'Please choose a member to report on.');
    header('Location: ' . base_url('reports/analyzer.php'));
    exit;
}

// Member with plan, trainer and computed attendance % / last visit
$stmt = $pdo->prepare('SELECT * FROM v_member_summary WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$m = $stmt->fetch();

if (!$m) {
    flash('error', 'Member not found.');
    header('Location: ' . base_url('reports/analyzer.php'));
    exit;
}

$page_title = 'Report Card — ' . $m['member_name'];

// ---------------------------------------------------------------
// All attendance dates for this member (used for the streak helper)
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT att_date FROM attendance WHERE member_id = :id ORDER BY att_date'
);
$stmt->execute([':id' => $member_id]);
$all_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
$streaks   = compute_streaks($all_dates);

// ---------------------------------------------------------------
// Last 30 days heat-strip: one cell per calendar day.
// We fetch the 30-day window keyed by date, then walk the calendar.
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT att_date, check_in, check_out, duration_min
     FROM attendance
     WHERE member_id = :id
       AND att_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     ORDER BY att_date'
);
$stmt->execute([':id' => $member_id]);
$window_rows = [];
foreach ($stmt->fetchAll() as $row) {
    $window_rows[$row['att_date']] = $row;
}

$heat_cells = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $heat_cells[] = [
        'date'     => $date,
        'present'  => isset($window_rows[$date]),
        'duration' => $window_rows[$date]['duration_min'] ?? null,
        'check_in' => $window_rows[$date]['check_in'] ?? null,
    ];
}
$present_30 = count($window_rows);

// ---------------------------------------------------------------
// Payment history + total paid
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT pay.*, p.plan_name
     FROM payment pay JOIN plan p ON p.plan_id = pay.plan_id
     WHERE pay.member_id = :id
     ORDER BY pay.paid_date DESC, pay.payment_id DESC'
);
$stmt->execute([':id' => $member_id]);
$payments   = $stmt->fetchAll();
$total_paid = array_sum(array_column($payments, 'amount'));

// ---------------------------------------------------------------
// This member's own visit stats, aggregated in SQL
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT COUNT(*)                      AS total_visits,
            ROUND(AVG(duration_min), 0)   AS avg_duration,
            MAX(duration_min)             AS max_duration,
            MIN(att_date)                 AS first_visit
     FROM attendance WHERE member_id = :id'
);
$stmt->execute([':id' => $member_id]);
$visit_stats = $stmt->fetch();

// Favourite hour of day for this member
$stmt = $pdo->prepare(
    'SELECT HOUR(check_in) AS hr, COUNT(*) AS c
     FROM attendance WHERE member_id = :id
     GROUP BY HOUR(check_in) ORDER BY c DESC LIMIT 1'
);
$stmt->execute([':id' => $member_id]);
$fav_hour_row = $stmt->fetch();
$fav_hour = $fav_hour_row
    ? date('g A', mktime((int)$fav_hour_row['hr'], 0, 0)) . ' – ' . date('g A', mktime((int)$fav_hour_row['hr'] + 1, 0, 0))
    : '—';

$pct  = (float) ($m['attendance_pct'] ?? 0);
$band = attendance_band($pct);
$risk = dropout_risk(
    $m['days_since_visit'] !== null ? (int) $m['days_since_visit'] : null,
    $m['status']
);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">Member Report Card</h2>
            <p class="text-muted mb-0">Module 4.11 — Individual analyzer drill-down</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="bi bi-printer-fill me-1"></i> Print
            </button>
            <a href="<?= base_url('members/edit.php?id=' . $member_id . '&tab=renew') ?>" class="btn btn-primary">
                <i class="bi bi-arrow-repeat me-1"></i> Renew
            </a>
            <a href="<?= base_url('reports/analyzer.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Analyzer
            </a>
        </div>
    </div>

    <!-- Report header band -->
    <div class="report-header mb-4">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <h3 class="mb-1"><?= h($m['member_name']) ?> <?= status_badge($m['status']) ?></h3>
                <p class="mb-0 text-muted">
                    <code><?= h($m['member_code']) ?></code> ·
                    <?= h($m['gender']) ?> ·
                    <?= h($m['phone']) ?>
                    <?= $m['email'] ? ' · ' . h($m['email']) : '' ?>
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="d-inline-block text-center px-3">
                    <div class="fs-2 fw-bold text-<?= $band['class'] ?>"><?= number_format($pct, 2) ?>%</div>
                    <span class="badge bg-<?= $band['class'] ?>"><?= $band['label'] ?></span>
                </div>
                <?php if ($risk !== ''): ?>
                <div class="d-inline-block text-center px-3">
                    <div class="fs-5 fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <span class="badge bg-<?= $risk === 'Likely Dropout' ? 'danger' : 'warning text-dark' ?>"><?= h($risk) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stat tiles -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card kpi-primary">
                <div class="kpi-value"><?= (int)$visit_stats['total_visits'] ?></div>
                <div class="kpi-label">Total Visits</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card kpi-success">
                <div class="kpi-value"><?= $streaks['current'] ?></div>
                <div class="kpi-label">Current Streak</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card kpi-accent">
                <div class="kpi-value"><?= $streaks['longest'] ?></div>
                <div class="kpi-label">Longest Streak</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card kpi-info">
                <div class="kpi-value"><?= $present_30 ?>/30</div>
                <div class="kpi-label">Last 30 Days</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card kpi-purple">
                <div class="kpi-value"><?= (int)$visit_stats['avg_duration'] ?> min</div>
                <div class="kpi-label">Avg Workout</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card kpi-warning">
                <div class="kpi-value"><?= $m['days_since_visit'] !== null ? (int)$m['days_since_visit'] : '—' ?></div>
                <div class="kpi-label">Days Since Visit</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 30-day heat strip -->
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Attendance — Last 30 Days</h5>
                        <p class="chart-subtitle text-muted small mb-0">
                            Each cell is one day. Filled = attended, hover for the time and duration.
                        </p>
                    </div>
                    <div class="heat-legend small text-muted">
                        <span class="heat-cell heat-absent"></span> Absent
                        <span class="heat-cell heat-short ms-2"></span> &lt; 60 min
                        <span class="heat-cell heat-mid ms-2"></span> 60–90 min
                        <span class="heat-cell heat-long ms-2"></span> &gt; 90 min
                    </div>
                </div>
                <div class="chart-body">
                    <div class="heat-strip">
                        <?php foreach ($heat_cells as $c):
                            if (!$c['present']) {
                                $cls = 'heat-absent';
                                $tip = fmt_date($c['date']) . ' — absent';
                            } else {
                                $d = $c['duration'];
                                $cls = $d === null ? 'heat-short' : ($d > 90 ? 'heat-long' : ($d >= 60 ? 'heat-mid' : 'heat-short'));
                                $tip = fmt_date($c['date']) . ' — in at ' . fmt_time($c['check_in'])
                                     . ($d !== null ? ", {$d} min" : '');
                            }
                        ?>
                            <span class="heat-cell <?= $cls ?>" title="<?= h($tip) ?>"
                                  aria-label="<?= h($tip) ?>"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-2">
                        <span><?= fmt_date($heat_cells[0]['date']) ?></span>
                        <span>Today</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Membership details -->
        <div class="col-12 col-lg-4">
            <div class="form-card h-100">
                <div class="form-card-header"><i class="bi bi-card-list me-2 text-accent"></i>Membership</div>
                <div class="form-card-body">
                    <table class="table table-borderless table-sm mb-0 profile-table">
                        <tr><th>Plan</th><td><?= h($m['plan_name']) ?> (<?= (int)$m['duration_months'] ?> mo)</td></tr>
                        <tr><th>Fee</th><td>₹<?= number_format($m['fee'], 0) ?></td></tr>
                        <tr><th>Trainer</th><td><?= h($m['trainer_name'] ?? 'Unassigned') ?></td></tr>
                        <tr><th>Specialization</th><td><?= h($m['specialization'] ?? '—') ?></td></tr>
                        <tr><th>Join Date</th><td><?= fmt_date($m['join_date']) ?></td></tr>
                        <tr><th>Expiry Date</th><td><?= fmt_date($m['expiry_date']) ?></td></tr>
                        <tr><th>First Visit</th><td><?= fmt_date($visit_stats['first_visit']) ?></td></tr>
                        <tr><th>Last Visit</th><td><?= fmt_date($m['last_visit']) ?></td></tr>
                        <tr><th>Favourite Hour</th><td><?= h($fav_hour) ?></td></tr>
                        <tr><th>Longest Workout</th><td><?= $visit_stats['max_duration'] !== null ? (int)$visit_stats['max_duration'] . ' min' : '—' ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment history -->
        <div class="col-12 col-lg-8">
            <div class="form-card h-100">
                <div class="form-card-header d-flex justify-content-between">
                    <span><i class="bi bi-receipt me-2 text-accent"></i>Payment History</span>
                    <span class="badge bg-accent">₹<?= number_format($total_paid, 0) ?> lifetime value</span>
                </div>
                <div class="form-card-body p-0">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-cash-stack fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No payments recorded for this member.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-head">
                                    <tr><th>#</th><th>Paid Date</th><th>Plan</th><th>Amount</th><th>Mode</th><th>Next Due</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($payments as $i => $p): ?>
                                    <tr>
                                        <td class="text-muted small"><?= $i + 1 ?></td>
                                        <td><?= fmt_date($p['paid_date']) ?></td>
                                        <td><?= h($p['plan_name']) ?></td>
                                        <td>₹<?= number_format($p['amount'], 0) ?></td>
                                        <td><span class="badge bg-secondary"><?= h($p['mode']) ?></span></td>
                                        <td><?= fmt_date($p['next_due_date']) ?></td>
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

    <p class="text-muted small mt-4 mb-0">
        Attendance % is computed over the member's plan window
        (join date to the earlier of today and join date + plan duration), so it
        can never exceed 100%. Streaks count consecutive calendar days attended;
        the current streak resets once a day is missed.
    </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
