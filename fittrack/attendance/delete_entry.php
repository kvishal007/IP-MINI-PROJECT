<?php
/**
 * File    : attendance/delete_entry.php
 * Purpose : Delete one wrong attendance entry (a single row DELETE), used
 *           to correct a mis-punched register. Shows a confirmation page
 *           on GET and performs the delete on POST.
 * Module  : Module 5 — Deletion (single attendance row)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo    = get_db();
$att_id = (int) ($_GET['id'] ?? $_POST['att_id'] ?? 0);

if ($att_id <= 0) {
    flash('error', 'Invalid attendance entry.');
    header('Location: ' . base_url('attendance/today.php'));
    exit;
}

// Load the entry with its member, for the confirmation message
$stmt = $pdo->prepare(
    'SELECT a.*, m.member_name, m.member_code, m.member_id
     FROM attendance a
     JOIN member m ON m.member_id = a.member_id
     WHERE a.att_id = :aid'
);
$stmt->execute([':aid' => $att_id]);
$entry = $stmt->fetch();

if (!$entry) {
    flash('error', 'Attendance entry not found — it may already be deleted.');
    header('Location: ' . base_url('attendance/today.php'));
    exit;
}

// Where to go back to after deleting
$return_to = $_POST['return_to'] ?? $_GET['return_to'] ?? 'today';
$back_url  = $return_to === 'history'
    ? base_url('attendance/history.php?member_id=' . $entry['member_id'])
    : base_url('attendance/today.php?date=' . urlencode($entry['att_date']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $stmt = $pdo->prepare('DELETE FROM attendance WHERE att_id = :aid');
    $stmt->execute([':aid' => $att_id]);

    flash('success', "Attendance entry for {$entry['member_name']} on "
        . fmt_date($entry['att_date']) . ' was deleted.');
    header('Location: ' . $back_url);
    exit;
}

$page_title = 'Delete Attendance Entry';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="form-card border-danger">
                <div class="form-card-header bg-danger-soft text-danger">
                    <i class="bi bi-trash-fill me-2"></i>Delete Attendance Entry
                </div>
                <div class="form-card-body">
                    <p>You are about to delete this attendance record:</p>
                    <table class="table table-sm profile-table">
                        <tr><th>Member</th><td><?= h($entry['member_name']) ?> (<code><?= h($entry['member_code']) ?></code>)</td></tr>
                        <tr><th>Date</th><td><?= fmt_date($entry['att_date']) ?></td></tr>
                        <tr><th>Check In</th><td><?= fmt_time($entry['check_in']) ?></td></tr>
                        <tr><th>Check Out</th><td><?= fmt_time($entry['check_out']) ?></td></tr>
                        <tr><th>Duration</th><td><?= $entry['duration_min'] !== null ? $entry['duration_min'] . ' min' : '—' ?></td></tr>
                    </table>
                    <p class="text-muted small">
                        This removes only this one row. The member's other attendance
                        records and their membership are untouched.
                    </p>
                    <form method="POST" action="delete_entry.php">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="att_id" value="<?= $att_id ?>">
                        <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
                        <div class="d-flex justify-content-between">
                            <a href="<?= $back_url ?>" class="btn btn-outline-secondary">Back</a>
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash-fill me-1"></i> Delete Entry
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
