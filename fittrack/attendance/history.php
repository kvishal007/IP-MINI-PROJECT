<?php
/**
 * File    : attendance/history.php
 * Purpose : Full attendance history with filters (member, date range) and
 *           pagination. Also used to correct a wrong entry, and can be
 *           exported to CSV.
 * Module  : Module 2 / Module 4 — Attendance history and reporting
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = 'Attendance History';

// ---------------------------------------------------------------
// Filters
// ---------------------------------------------------------------
$member_id = (int) ($_GET['member_id'] ?? 0);
$from_date = trim($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to_date   = trim($_GET['to']   ?? date('Y-m-d'));
$per_page  = 15;
$page_num  = max(1, (int) ($_GET['pg'] ?? 1));
$offset    = ($page_num - 1) * $per_page;

$where  = ['a.att_date BETWEEN :from AND :to'];
$params = [':from' => $from_date, ':to' => $to_date];

if ($member_id > 0) {
    $where[]              = 'a.member_id = :mid';
    $params[':mid']       = $member_id;
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// Total for pagination
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a $where_sql");
$stmt->execute($params);
$total_rows  = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));

// Page of data
$stmt = $pdo->prepare(
    "SELECT a.att_id, a.att_date, a.check_in, a.check_out, a.duration_min,
            m.member_id, m.member_code, m.member_name,
            p.plan_name, t.trainer_name
     FROM attendance a
     JOIN member m ON m.member_id = a.member_id
     JOIN plan p   ON p.plan_id   = m.plan_id
     LEFT JOIN trainer t ON t.trainer_id = m.trainer_id
     $where_sql
     ORDER BY a.att_date DESC, a.check_in DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Summary for the filtered range (aggregation done in SQL)
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS visits,
            COUNT(DISTINCT a.member_id) AS unique_members,
            ROUND(AVG(a.duration_min), 0) AS avg_duration
     FROM attendance a $where_sql"
);
$stmt->execute($params);
$summary = $stmt->fetch();

// Member dropdown
$members_list = $pdo->query(
    'SELECT member_id, member_code, member_name FROM member ORDER BY member_name'
)->fetchAll();

// Preserve filters in links
$qs = http_build_query([
    'member_id' => $member_id ?: '',
    'from'      => $from_date,
    'to'        => $to_date,
]);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">Attendance History</h2>
            <p class="text-muted mb-0"><?= number_format($total_rows) ?> record(s) in range</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= base_url('reports/export_csv.php?report=attendance&' . $qs) ?>" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
            </a>
            <a href="<?= base_url('attendance/today.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-calendar-check-fill me-1"></i> Today
            </a>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="history.php" class="filter-bar mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small text-muted">Member</label>
                <select class="form-select" name="member_id">
                    <option value="0">All Members</option>
                    <?php foreach ($members_list as $m): ?>
                        <option value="<?= $m['member_id'] ?>" <?= $member_id === (int)$m['member_id'] ? 'selected' : '' ?>>
                            <?= h($m['member_code']) ?> — <?= h($m['member_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted">From</label>
                <input type="date" class="form-control" name="from" value="<?= h($from_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted">To</label>
                <input type="date" class="form-control" name="to" value="<?= h($to_date) ?>">
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                <a href="history.php" class="btn btn-outline-secondary flex-fill">Reset</a>
            </div>
        </div>
    </form>

    <!-- Summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="kpi-card kpi-primary">
                <div class="kpi-value"><?= number_format((int)$summary['visits']) ?></div>
                <div class="kpi-label">Total Visits</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="kpi-card kpi-info">
                <div class="kpi-value"><?= number_format((int)$summary['unique_members']) ?></div>
                <div class="kpi-label">Unique Members</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="kpi-card kpi-accent">
                <div class="kpi-value"><?= (int)$summary['avg_duration'] ?> min</div>
                <div class="kpi-label">Avg Workout Duration</div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <?php if (empty($rows)): ?>
            <div class="empty-state py-5">
                <i class="bi bi-calendar-x fs-1 text-muted"></i>
                <h5 class="mt-3">No attendance records in this range</h5>
                <p class="text-muted">Try widening the date range or clearing the member filter.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-head">
                        <tr>
                            <th>Date</th><th>Code</th><th>Member</th><th>Trainer</th>
                            <th>In</th><th>Out</th><th>Duration</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= fmt_date($r['att_date']) ?></td>
                            <td><code><?= h($r['member_code']) ?></code></td>
                            <td>
                                <a href="<?= base_url('members/view.php?id=' . $r['member_id']) ?>" class="text-decoration-none">
                                    <?= h($r['member_name']) ?>
                                </a>
                            </td>
                            <td><?= h($r['trainer_name'] ?? '—') ?></td>
                            <td><?= fmt_time($r['check_in']) ?></td>
                            <td><?= fmt_time($r['check_out']) ?></td>
                            <td><?= $r['duration_min'] !== null ? $r['duration_min'] . ' min' : '—' ?></td>
                            <td>
                                <a href="<?= base_url('attendance/delete_entry.php?id=' . $r['att_id'] . '&return_to=history') ?>"
                                   class="btn btn-xs btn-outline-danger" title="Delete entry">
                                    <i class="bi bi-trash-fill"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <nav class="p-3 border-top">
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= $qs ?>&pg=<?= $page_num - 1 ?>">&laquo; Prev</a>
                    </li>
                    <?php
                    // Show a compact window of page numbers
                    $start = max(1, $page_num - 2);
                    $end   = min($total_pages, $start + 4);
                    for ($pg = $start; $pg <= $end; $pg++): ?>
                        <li class="page-item <?= $pg === $page_num ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= $qs ?>&pg=<?= $pg ?>"><?= $pg ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= $qs ?>&pg=<?= $page_num + 1 ?>">Next &raquo;</a>
                    </li>
                </ul>
                <p class="text-center text-muted small mt-2 mb-0">
                    Page <?= $page_num ?> of <?= $total_pages ?>
                </p>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
