<?php
/**
 * File    : attendance/bulk.php
 * Purpose : Bulk attendance marking — pick a date, tick a list of Active
 *           members, and insert one attendance row each in a single
 *           TRANSACTION. Members already marked for that date are shown
 *           as already-present and skipped (UNIQUE(member_id, att_date)).
 * Module  : Module 2 — Attendance Marking (batch INSERT)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = 'Bulk Mark Attendance';
$errors     = [];

// Date being marked (defaults to today, never in the future)
$mark_date = $_POST['att_date'] ?? $_GET['date'] ?? date('Y-m-d');
if ($mark_date > date('Y-m-d')) {
    $mark_date = date('Y-m-d');
}

// ---------------------------------------------------------------
// Handle bulk submission
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['member_ids'])) {
    validate_csrf();

    $member_ids = array_map('intval', (array) $_POST['member_ids']);
    $member_ids = array_filter($member_ids, fn($id) => $id > 0);
    $check_in   = trim($_POST['check_in'] ?? '07:00');
    $duration   = (int) ($_POST['duration_min'] ?? 60);

    if (empty($member_ids)) {
        $errors[] = 'Please select at least one member.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $check_in)) {
        $errors[] = 'Enter a valid check-in time (HH:MM).';
    }
    if ($duration < 1 || $duration > 480) {
        $errors[] = 'Duration must be between 1 and 480 minutes.';
    }

    if (empty($errors)) {
        $check_in_full = $check_in . ':00';
        // check_out = check_in + duration, computed in SQL so the stored
        // duration_min and the two TIME columns can never disagree.
        $inserted = 0;
        $skipped  = 0;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO attendance
                    (member_id, att_date, check_in, check_out, duration_min)
                 VALUES
                    (:mid, :d, :cin, ADDTIME(:cin2, SEC_TO_TIME(:dur * 60)), :dur2)'
            );
            foreach ($member_ids as $mid) {
                $stmt->execute([
                    ':mid'  => $mid,
                    ':d'    => $mark_date,
                    ':cin'  => $check_in_full,
                    ':cin2' => $check_in_full,
                    ':dur'  => $duration,
                    ':dur2' => $duration,
                ]);
                // INSERT IGNORE returns 0 affected rows when the UNIQUE
                // (member_id, att_date) key already has a row for that day.
                if ($stmt->rowCount() > 0) { $inserted++; } else { $skipped++; }
            }
            $pdo->commit();

            $msg = "{$inserted} attendance record(s) marked for " . fmt_date($mark_date) . '.';
            if ($skipped > 0) {
                $msg .= " {$skipped} skipped (already marked for that date).";
            }
            flash('success', $msg);
            header('Location: ' . base_url('attendance/today.php?date=' . urlencode($mark_date)));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Bulk marking failed: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------
// Load Active members, flagging who is already marked on that date
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT m.member_id, m.member_code, m.member_name, m.phone,
            p.plan_name, t.trainer_name,
            a.att_id
     FROM member m
     JOIN plan p   ON p.plan_id = m.plan_id
     LEFT JOIN trainer t ON t.trainer_id = m.trainer_id
     LEFT JOIN attendance a ON a.member_id = m.member_id AND a.att_date = :d
     WHERE m.status = 'Active'
     ORDER BY m.member_name"
);
$stmt->execute([':d' => $mark_date]);
$members = $stmt->fetchAll();

$already_count = count(array_filter($members, fn($m) => $m['att_id'] !== null));

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="section-heading">Bulk Mark Attendance</h2>
            <p class="text-muted">Module 2 — Batch INSERT inside a transaction</p>
        </div>
        <a href="<?= base_url('attendance/mark.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-qr-code-scan me-1"></i> Single Check-In
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="bulk.php" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <div class="form-card mb-4">
            <div class="form-card-header"><i class="bi bi-sliders me-2 text-accent"></i>Batch Settings</div>
            <div class="form-card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label required-field">Attendance Date</label>
                        <input type="date" class="form-control" name="att_date" value="<?= h($mark_date) ?>"
                               max="<?= date('Y-m-d') ?>" onchange="document.getElementById('dateReload').click()">
                        <button type="submit" formmethod="GET" formaction="bulk.php" name="date"
                                value="<?= h($mark_date) ?>" id="dateReload" class="d-none"></button>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required-field">Check-In Time</label>
                        <input type="time" class="form-control" name="check_in" value="07:00" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required-field">Duration (minutes)</label>
                        <input type="number" class="form-control" name="duration_min" value="60" min="1" max="480" required>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-primary w-100" id="selectAllBtn">
                            <i class="bi bi-check-all me-1"></i> Select / Clear All
                        </button>
                    </div>
                </div>
                <p class="text-muted small mb-0 mt-3">
                    Marking for <strong><?= fmt_date($mark_date) ?></strong>.
                    <?php if ($already_count > 0): ?>
                        <?= $already_count ?> member(s) already have a record for this date and are shown greyed out.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="table-card mb-4">
            <?php if (empty($members)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-people fs-1 text-muted"></i>
                    <h5 class="mt-3">No active members to mark</h5>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-head">
                            <tr>
                                <th style="width:48px;"><i class="bi bi-check2-square"></i></th>
                                <th>Code</th><th>Name</th><th>Phone</th>
                                <th>Plan</th><th>Trainer</th><th>Status on <?= fmt_date($mark_date) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($members as $m): ?>
                            <?php $already = $m['att_id'] !== null; ?>
                            <tr class="<?= $already ? 'table-light text-muted' : '' ?>">
                                <td>
                                    <input class="form-check-input bulk-check" type="checkbox"
                                           name="member_ids[]" value="<?= $m['member_id'] ?>"
                                           <?= $already ? 'disabled' : '' ?>
                                           aria-label="Mark <?= h($m['member_name']) ?>">
                                </td>
                                <td><code><?= h($m['member_code']) ?></code></td>
                                <td><?= h($m['member_name']) ?></td>
                                <td><?= h($m['phone']) ?></td>
                                <td><?= h($m['plan_name']) ?></td>
                                <td><?= h($m['trainer_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ($already): ?>
                                        <span class="badge bg-success">Already marked</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">Not marked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <span class="align-self-center text-muted small me-2" id="selCount">0 selected</span>
            <button type="submit" class="btn btn-accent btn-lg">
                <i class="bi bi-check-circle-fill me-1"></i> Mark Selected Members
            </button>
        </div>
    </form>
</div>

<script>
// Select / clear all (only enabled checkboxes)
const boxes = Array.from(document.querySelectorAll('.bulk-check:not([disabled])'));
const selCount = document.getElementById('selCount');

function updateCount() {
    const n = boxes.filter(b => b.checked).length;
    selCount.textContent = n + ' selected';
}
document.getElementById('selectAllBtn').addEventListener('click', function () {
    const allChecked = boxes.every(b => b.checked);
    boxes.forEach(b => { b.checked = !allChecked; });
    updateCount();
});
boxes.forEach(b => b.addEventListener('change', updateCount));
updateCount();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
