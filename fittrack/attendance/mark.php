<?php
/**
 * File    : attendance/mark.php
 * Purpose : Search a member (by code / name / phone) and check them in.
 *           A second click the same day checks them out and computes the
 *           workout duration. Blocks check-in for Expired/Cancelled members
 *           with a "Renew now" link, and blocks a second check-in the same
 *           day via the attendance table's UNIQUE(member_id, att_date).
 * Module  : Module 2 — Attendance Marking (INSERT + UPDATE)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo         = get_db();
$page_title  = 'Mark Attendance';
$today       = date('Y-m-d');
$notice      = '';
$notice_type = 'info';

// ---------------------------------------------------------------
// Handle check-in / check-out submission
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $member_id = (int) ($_POST['member_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM member WHERE member_id = :id');
    $stmt->execute([':id' => $member_id]);
    $member = $stmt->fetch();

    if (!$member) {
        $notice = 'Member not found.';
        $notice_type = 'danger';
    } elseif (in_array($member['status'], ['Expired', 'Cancelled'], true)) {
        // Block check-in for members whose membership isn't valid right now
        $notice = "{$member['member_name']} cannot check in — membership is "
                . "{$member['status']}. "
                . '<a href="' . base_url("members/edit.php?id={$member_id}&tab=renew") . '" '
                . 'class="alert-link">Renew now &raquo;</a>';
        $notice_type = 'warning';
    } else {
        // Is there already an attendance row for this member today?
        $stmt = $pdo->prepare(
            'SELECT * FROM attendance WHERE member_id = :id AND att_date = :d'
        );
        $stmt->execute([':id' => $member_id, ':d' => $today]);
        $existing = $stmt->fetch();

        if (!$existing) {
            // ---- CHECK-IN (first visit today) ----
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO attendance (member_id, att_date, check_in)
                     VALUES (:id, :d, :t)'
                );
                $stmt->execute([':id' => $member_id, ':d' => $today, ':t' => date('H:i:s')]);
                $notice = "{$member['member_name']} checked in at " . date('h:i A') . '.';
                $notice_type = 'success';
            } catch (PDOException $e) {
                // UNIQUE(member_id, att_date) guards against a race condition
                $notice = 'Could not check in — a record for today may already exist.';
                $notice_type = 'danger';
            }
        } elseif ($existing['check_out'] === null) {
            // ---- CHECK-OUT (second click, same day) ----
            $check_out = date('H:i:s');
            // Duration in whole minutes. TIMEDIFF/TIME_TO_SEC work directly on
            // TIME columns — TIMESTAMPDIFF would try to read them as DATETIME
            // and reject the value.
            $stmt = $pdo->prepare(
                'UPDATE attendance
                 SET check_out = :co,
                     duration_min = FLOOR(TIME_TO_SEC(TIMEDIFF(:co2, check_in)) / 60)
                 WHERE att_id = :aid'
            );
            $stmt->execute([':co' => $check_out, ':co2' => $check_out, ':aid' => $existing['att_id']]);
            $notice = "{$member['member_name']} checked out at " . date('h:i A') . '.';
            $notice_type = 'success';
        } else {
            // Already checked in AND out today
            $notice = "{$member['member_name']} has already checked in and out today "
                    . '(' . fmt_time($existing['check_in']) . ' – ' . fmt_time($existing['check_out']) . ').';
            $notice_type = 'info';
        }
    }
}

// ---------------------------------------------------------------
// Search
// ---------------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$results = [];

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT m.member_id, m.member_code, m.member_name, m.phone, m.status,
                a.att_id, a.check_in, a.check_out
         FROM member m
         LEFT JOIN attendance a ON a.member_id = m.member_id AND a.att_date = :today
         WHERE m.member_name LIKE :q OR m.member_code LIKE :q2 OR m.phone LIKE :q3
         ORDER BY m.member_name
         LIMIT 20"
    );
    $like = "%{$search}%";
    $stmt->execute([':today' => $today, ':q' => $like, ':q2' => $like, ':q3' => $like]);
    $results = $stmt->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="section-heading">Mark Attendance</h2>
            <p class="text-muted">Module 2 — Daily Register · <?= date('l, d M Y') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= base_url('attendance/today.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-calendar-check-fill me-1"></i> Today's Register
            </a>
            <a href="<?= base_url('attendance/bulk.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-list-check me-1"></i> Bulk Mark
            </a>
        </div>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-<?= $notice_type ?> alert-dismissible fade show" role="alert">
            <?= $notice /* contains a trusted, internally-built link; no raw user input */ ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="form-card mb-4">
        <div class="form-card-body">
            <form method="GET" action="mark.php" class="d-flex gap-2">
                <div class="input-group input-group-lg">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q" id="searchBox"
                           placeholder="Search by member code, name, or phone…"
                           value="<?= h($search) ?>" autofocus>
                    <button type="submit" class="btn btn-accent">Search</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($search !== ''): ?>
        <div class="table-card">
            <?php if (empty($results)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-person-x fs-1 text-muted"></i>
                    <h5 class="mt-3">No members matched "<?= h($search) ?>"</h5>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-head">
                            <tr>
                                <th>Code</th><th>Name</th><th>Phone</th><th>Status</th>
                                <th>Today</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><code><?= h($r['member_code']) ?></code></td>
                                <td><?= h($r['member_name']) ?></td>
                                <td><?= h($r['phone']) ?></td>
                                <td><?= status_badge($r['status']) ?></td>
                                <td>
                                    <?php if ($r['att_id'] === null): ?>
                                        <span class="text-muted small">Not checked in</span>
                                    <?php elseif ($r['check_out'] === null): ?>
                                        <span class="badge bg-info">In: <?= fmt_time($r['check_in']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <?= fmt_time($r['check_in']) ?> – <?= fmt_time($r['check_out']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (in_array($r['status'], ['Expired','Cancelled'], true)): ?>
                                        <a href="<?= base_url("members/edit.php?id={$r['member_id']}&tab=renew") ?>" class="btn btn-sm btn-outline-warning">
                                            Renew to Check In
                                        </a>
                                    <?php elseif ($r['att_id'] !== null && $r['check_out'] !== null): ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled>Done for Today</button>
                                    <?php else: ?>
                                        <form method="POST" action="mark.php?q=<?= urlencode($search) ?>" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="member_id" value="<?= $r['member_id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $r['att_id'] === null ? 'btn-success' : 'btn-primary' ?>">
                                                <?= $r['att_id'] === null ? '<i class="bi bi-box-arrow-in-right me-1"></i>Check In' : '<i class="bi bi-box-arrow-right me-1"></i>Check Out' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="empty-state py-5">
            <i class="bi bi-search fs-1 text-muted"></i>
            <h5 class="mt-3">Search for a member to mark attendance</h5>
            <p class="text-muted">Type a member code, name, or phone number above.</p>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
