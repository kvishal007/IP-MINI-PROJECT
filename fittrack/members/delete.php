<?php
/**
 * File    : members/delete.php
 * Purpose : PERMANENT delete of a member — admin only. Removes the member
 *           row and cascades their attendance and payment rows inside a
 *           single TRANSACTION (the schema also declares ON DELETE CASCADE
 *           on both foreign keys as a second line of defence).
 * Module  : Module 5 — Cancellation / Deletion (hard delete)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin(); // hard delete is admin-only

$pdo       = get_db();
$member_id = (int) ($_GET['id'] ?? $_POST['member_id'] ?? 0);

if ($member_id <= 0) {
    flash('error', 'Invalid member selected.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM member WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$member = $stmt->fetch();

if (!$member) {
    flash('error', 'Member not found — it may already be deleted.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    try {
        $pdo->beginTransaction();

        // Attendance and payment rows are removed explicitly inside the
        // transaction (belt-and-braces alongside the schema's own
        // ON DELETE CASCADE, and it keeps the operation self-documenting).
        $pdo->prepare('DELETE FROM attendance WHERE member_id = :id')->execute([':id' => $member_id]);
        $pdo->prepare('DELETE FROM payment    WHERE member_id = :id')->execute([':id' => $member_id]);
        $pdo->prepare('DELETE FROM member     WHERE member_id = :id')->execute([':id' => $member_id]);

        $pdo->commit();
        flash('success', "Member {$member['member_code']} and all their records were permanently deleted.");
    } catch (PDOException $e) {
        $pdo->rollBack();
        flash('error', 'Could not delete member: ' . $e->getMessage());
    }

    header('Location: ' . base_url('members/list.php'));
    exit;
}

// GET: show a confirmation page (also reachable directly, in case JS confirm() was skipped)
$page_title = 'Permanently Delete Member';
include __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$att_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM payment WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$pay_count = (int) $stmt->fetchColumn();
?>

<div class="container-fluid px-4 py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="form-card border-danger">
                <div class="form-card-header bg-danger-soft text-danger">
                    <i class="bi bi-trash-fill me-2"></i>Permanently Delete Member
                </div>
                <div class="form-card-body">
                    <div class="alert alert-danger">
                        <strong>This action cannot be undone.</strong> Deleting
                        <strong><?= h($member['member_name']) ?></strong>
                        (<code><?= h($member['member_code']) ?></code>) will also permanently remove:
                        <ul class="mb-0 mt-2">
                            <li><?= $att_count ?> attendance record(s)</li>
                            <li><?= $pay_count ?> payment record(s)</li>
                        </ul>
                    </div>
                    <p class="text-muted small">
                        If you only want to stop this member's gym access while keeping their
                        history, use <strong>Cancel Membership</strong> instead (a reversible
                        soft delete) rather than this permanent delete.
                    </p>

                    <form method="POST" action="delete.php">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="member_id" value="<?= $member_id ?>">
                        <div class="d-flex justify-content-between">
                            <a href="<?= base_url('members/view.php?id=' . $member_id) ?>" class="btn btn-outline-secondary">Cancel, Go Back</a>
                            <button type="submit" class="btn btn-danger"
                                onclick="return confirm('Type-checked: really permanently delete <?= h($member['member_name']) ?>? This cannot be undone.');">
                                <i class="bi bi-trash-fill me-1"></i> Yes, Delete Permanently
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
