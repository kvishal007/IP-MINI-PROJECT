<?php
/**
 * File    : attendance/today.php
 * Purpose : Today's Register — every member who has checked in today,
 *           with check-in / check-out / duration / trainer, plus a quick
 *           link to fix a wrong entry.
 * Module  : Module 2 — Attendance Marking (read view)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = "Today's Register";
$date       = $_GET['date'] ?? date('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT a.att_id, a.att_date, a.check_in, a.check_out, a.duration_min,
            m.member_id, m.member_code, m.member_name, m.status,
            p.plan_name, t.trainer_name
     FROM attendance a
     JOIN member m ON m.member_id = a.member_id
     JOIN plan p   ON p.plan_id   = m.plan_id
     LEFT JOIN trainer t ON t.trainer_id = m.trainer_id
     WHERE a.att_date = :d
     ORDER BY a.check_in ASC"
);
$stmt->execute([':d' => $date]);
$rows = $stmt->fetchAll();

// Summary counts
$total_visits   = count($rows);
$still_in       = count(array_filter($rows, fn($r) => $r['check_out'] === null));
$checked_out    = $total_visits - $still_in;
$avg_duration   = 0;
$durations      = array_filter(array_column($rows, 'duration_min'));
if (!empty($durations)) {
    $avg_duration = round(array_sum($durations) / count($durations));
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">Today's Register</h2>
            <p class="text-muted">Module 2 — Daily Attendance View</p>
        </div>
        <form method="GET" action="today.php" class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 small text-muted">Date:</label>
            <input type="date" class="form-control form-control-sm" name="date"
                   value="<?= h($date) ?>" max="<?= date('Y-m-d') ?>"
                   onchange="this.form.submit()">
            <a href="<?= base_url('attendance/mark.php') ?>" class="btn btn-accent btn-sm">
                <i class="bi bi-qr-code-scan me-1"></i> Mark Attendance
            </a>
        </form>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-primary"><div class="kpi-value"><?= $total_visits ?></div><div class="kpi-label">Total Visits</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-info"><div class="kpi-value"><?= $still_in ?></div><div class="kpi-label">Currently In</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-success"><div class="kpi-value"><?= $checked_out ?></div><div class="kpi-label">Checked Out</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-accent"><div class="kpi-value"><?= $avg_duration ?> min</div><div class="kpi-label">Avg Duration</div></div>
        </div>
    </div>

    <div class="table-card">
        <?php if (empty($rows)): ?>
            <div class="empty-state py-5">
                <i class="bi bi-calendar-x fs-1 text-muted"></i>
                <h5 class="mt-3">No check-ins recorded for <?= fmt_date($date) ?></h5>
                <a href="<?= base_url('attendance/mark.php') ?>" class="btn btn-accent mt-2">
                    <i class="bi bi-qr-code-scan me-1"></i> Mark Attendance
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head">
                        <tr>
                            <th>#</th><th>Code</th><th>Name</th><th>Trainer</th>
                            <th>Plan</th><th>Check In</th><th>Check Out</th>
                            <th>Duration</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td><code><?= h($r['member_code']) ?></code></td>
                            <td>
                                <a href="<?= base_url('members/view.php?id=' . $r['member_id']) ?>" class="text-decoration-none fw-semibold">
                                    <?= h($r['member_name']) ?>
                                </a>
                            </td>
                            <td><?= h($r['trainer_name'] ?? '—') ?></td>
                            <td><?= h($r['plan_name']) ?></td>
                            <td><span class="badge bg-info"><?= fmt_time($r['check_in']) ?></span></td>
                            <td>
                                <?php if ($r['check_out']): ?>
                                    <span class="badge bg-success"><?= fmt_time($r['check_out']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Still in gym</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $r['duration_min'] !== null ? $r['duration_min'] . ' min' : '—' ?></td>
                            <td>
                                <a href="<?= base_url('attendance/delete_entry.php?id=' . $r['att_id']) ?>"
                                   class="btn btn-xs btn-outline-danger" title="Delete this entry"
                                   onclick="return confirm('Delete this attendance entry for <?= h($r['member_name']) ?>?');">
                                    <i class="bi bi-trash-fill"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
