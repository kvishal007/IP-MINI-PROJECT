<?php
/**
 * File    : members/list.php
 * Purpose : Paginated, searchable, sortable member list with status filters.
 *           Links to view, edit, cancel, delete.
 * Module  : Module 1 (Registration) / Module 5 (Cancellation)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = get_db();
$page_title = 'All Members';

// ---------------------------------------------------------------
// Filters and pagination
// ---------------------------------------------------------------
$search     = trim($_GET['q']       ?? '');
$status_f   = trim($_GET['status']  ?? '');
$plan_f     = (int)($_GET['plan']   ?? 0);
$trainer_f  = (int)($_GET['trainer']?? 0);
$per_page   = 10;
$page_num   = max(1, (int)($_GET['pg'] ?? 1));
$offset     = ($page_num - 1) * $per_page;

// Build WHERE clause dynamically with bound parameters
$where_parts = [];
$params      = [];

if ($search !== '') {
    $where_parts[] = '(m.member_name LIKE :q OR m.member_code LIKE :q2 OR m.phone LIKE :q3)';
    $params[':q']  = "%{$search}%";
    $params[':q2'] = "%{$search}%";
    $params[':q3'] = "%{$search}%";
}
if ($status_f !== '' && in_array($status_f, ['Active','Expired','Cancelled'])) {
    $where_parts[] = 'm.status = :status';
    $params[':status'] = $status_f;
}
if ($plan_f > 0) {
    $where_parts[] = 'm.plan_id = :plan_id';
    $params[':plan_id'] = $plan_f;
}
if ($trainer_f > 0) {
    $where_parts[] = 'm.trainer_id = :trainer_id';
    $params[':trainer_id'] = $trainer_f;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Total count for pagination — same alias (m) and WHERE clause as the data
// query below, both against v_member_summary (it already carries every
// member/plan/trainer column the filters and the table need).
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM v_member_summary m $where_sql"
);
$count_stmt->execute($params);
$total_rows  = (int) $count_stmt->fetchColumn();
$total_pages = (int) ceil($total_rows / $per_page);

// Main data query using the view for attendance %
$data_stmt = $pdo->prepare(
    "SELECT m.*
     FROM v_member_summary m
     $where_sql
     ORDER BY m.member_id ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $val) {
    $data_stmt->bindValue($key, $val);
}
$data_stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$data_stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$data_stmt->execute();
$members = $data_stmt->fetchAll();

// Filter dropdowns
$plans    = $pdo->query('SELECT plan_id, plan_name FROM plan ORDER BY fee')->fetchAll();
$trainers = $pdo->query('SELECT trainer_id, trainer_name FROM trainer ORDER BY trainer_name')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="section-heading">All Members</h2>
            <p class="text-muted"><?= h($total_rows) ?> member<?= $total_rows !== 1 ? 's' : '' ?> found</p>
        </div>
        <a href="<?= base_url('members/register.php') ?>" class="btn btn-accent">
            <i class="bi bi-person-plus-fill me-1"></i> Register New
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" action="list.php" class="filter-bar mb-4" id="filterForm">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small text-muted">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q"
                           placeholder="Name, code, or phone…"
                           value="<?= h($search) ?>" id="searchInput">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach (['Active','Expired','Cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status_f === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Plan</label>
                <select class="form-select" name="plan">
                    <option value="0">All Plans</option>
                    <?php foreach ($plans as $p): ?>
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
                    <?php foreach ($trainers as $t): ?>
                        <option value="<?= $t['trainer_id'] ?>" <?= $trainer_f === (int)$t['trainer_id'] ? 'selected' : '' ?>>
                            <?= h($t['trainer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                <a href="list.php" class="btn btn-outline-secondary flex-fill">Reset</a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="table-card">
        <?php if (empty($members)): ?>
            <div class="empty-state py-5">
                <i class="bi bi-people fs-1 text-muted"></i>
                <h5 class="mt-3">No members found</h5>
                <p class="text-muted">Try adjusting your search or filters.</p>
                <a href="<?= base_url('members/register.php') ?>" class="btn btn-accent mt-2">
                    <i class="bi bi-person-plus-fill me-1"></i> Register First Member
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="memberTable">
                    <thead class="table-head">
                        <tr>
                            <th>#</th>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Plan</th>
                            <th>Trainer</th>
                            <th>Expiry</th>
                            <th>Attendance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $i => $m): ?>
                        <?php
                            $pct  = (float)($m['attendance_pct'] ?? 0);
                            $band = attendance_band($pct);
                        ?>
                        <tr>
                            <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                            <td><code><?= h($m['member_code']) ?></code></td>
                            <td>
                                <a href="<?= base_url('members/view.php?id=') . h($m['member_id']) ?>" class="fw-semibold text-decoration-none">
                                    <?= h($m['member_name']) ?>
                                </a>
                                <div class="small text-muted"><?= h($m['gender']) ?></div>
                            </td>
                            <td><?= h($m['phone']) ?></td>
                            <td><?= h($m['plan_name']) ?></td>
                            <td><?= h($m['trainer_name'] ?? '—') ?></td>
                            <td>
                                <?= fmt_date($m['expiry_date']) ?>
                                <?php if ($m['status'] === 'Active' && strtotime($m['expiry_date']) < strtotime('+7 days')): ?>
                                    <span class="badge bg-warning text-dark ms-1">Soon</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-fill" style="height:6px;min-width:60px;">
                                        <div class="progress-bar bg-<?= $band['class'] ?>"
                                             style="width:<?= min(100, $pct) ?>%"></div>
                                    </div>
                                    <span class="small badge bg-<?= $band['class'] ?>"><?= $pct ?>%</span>
                                </div>
                            </td>
                            <td><?= status_badge($m['status']) ?></td>
                            <td>
                                <div class="action-btns">
                                    <a href="<?= base_url('members/view.php?id=') . h($m['member_id']) ?>"
                                       class="btn btn-xs btn-outline-info" title="View Profile">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>
                                    <a href="<?= base_url('members/edit.php?id=') . h($m['member_id']) ?>"
                                       class="btn btn-xs btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <?php if ($m['status'] !== 'Cancelled'): ?>
                                    <a href="<?= base_url('members/cancel.php?id=') . h($m['member_id']) ?>"
                                       class="btn btn-xs btn-outline-warning" title="Cancel">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="<?= base_url('members/edit.php?id=') . h($m['member_id']) ?>&restore=1"
                                       class="btn btn-xs btn-outline-success" title="Restore">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (is_admin()): ?>
                                    <a href="<?= base_url('members/delete.php?id=') . h($m['member_id']) ?>"
                                       class="btn btn-xs btn-outline-danger" title="Delete"
                                       onclick="return confirmDelete('<?= h($m['member_name']) ?>')">
                                        <i class="bi bi-trash-fill"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav class="p-3 border-top" aria-label="Member list pagination">
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?pg=<?= $page_num - 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&plan=<?= $plan_f ?>&trainer=<?= $trainer_f ?>">
                            &laquo; Prev
                        </a>
                    </li>
                    <?php for ($pg = 1; $pg <= $total_pages; $pg++): ?>
                        <li class="page-item <?= $pg === $page_num ? 'active' : '' ?>">
                            <a class="page-link" href="?pg=<?= $pg ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&plan=<?= $plan_f ?>&trainer=<?= $trainer_f ?>">
                                <?= $pg ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?pg=<?= $page_num + 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&plan=<?= $plan_f ?>&trainer=<?= $trainer_f ?>">
                            Next &raquo;
                        </a>
                    </li>
                </ul>
                <p class="text-center text-muted small mt-2">
                    Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?> members
                </p>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Cancelled Members Link -->
    <div class="mt-3">
        <a href="list.php?status=Cancelled" class="text-muted small">
            <i class="bi bi-archive me-1"></i> View Cancelled Members
        </a>
    </div>
</div>

<script>
function confirmDelete(name) {
    return confirm('⚠️ Permanently delete member "' + name + '"?\nThis will also remove all their attendance and payment records. This CANNOT be undone.');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
