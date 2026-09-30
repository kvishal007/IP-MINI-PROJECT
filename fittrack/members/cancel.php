<?php
/**
 * File    : members/cancel.php
 * Purpose : Cancel a membership — a SOFT delete. Confirmation with a
 *           reason (dropdown + free-text notes), sets status='Cancelled'
 *           and records cancel_date / cancel_reason. Attendance and
 *           payment history are kept intact.
 * Module  : Module 5 — Cancellation / Deletion (soft delete)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo       = get_db();
$member_id = (int) ($_GET['id'] ?? $_POST['member_id'] ?? 0);
$errors    = [];

if ($member_id <= 0) {
    flash('error', 'Invalid member selected.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

$reasons = [
    'Relocated to another city',
    'Financial constraints',
    'Health / medical reasons',
    'Not satisfied with services',
    'Switched to another gym',
    'Personal / family reasons',
    'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $reason = trim($_POST['reason'] ?? '');
    $notes  = trim($_POST['notes']  ?? '');

    if ($reason === '' || !in_array($reason, $reasons, true)) {
        $errors[] = 'Please select a cancellation reason.';
    }

    if (empty($errors)) {
        $full_reason = $reason === 'Other' && $notes !== '' ? $notes : $reason;
        if ($notes !== '' && $reason !== 'Other') {
            $full_reason .= ' — ' . $notes;
        }
        $full_reason = mb_substr($full_reason, 0, 100);

        $stmt = $pdo->prepare(
            "UPDATE member SET status='Cancelled', cancel_date=CURDATE(), cancel_reason=:reason
             WHERE member_id=:id"
        );
        $stmt->execute([':reason' => $full_reason, ':id' => $member_id]);

        flash('success', 'Membership cancelled. The member can be restored anytime from the Cancelled list.');
        header('Location: ' . base_url('members/list.php'));
        exit;
    }
}

$stmt = $pdo->prepare('SELECT * FROM member WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$member = $stmt->fetch();

if (!$member) {
    flash('error', 'Member not found.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

if ($member['status'] === 'Cancelled') {
    flash('warning', 'This member is already cancelled.');
    header('Location: ' . base_url('members/view.php?id=' . $member_id));
    exit;
}

$page_title = 'Cancel Membership';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="form-card">
                <div class="form-card-header bg-warning-soft">
                    <i class="bi bi-x-circle-fill me-2 text-warning"></i>Cancel Membership
                </div>
                <div class="form-card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-secondary">
                        <strong><?= h($member['member_name']) ?></strong> (<code><?= h($member['member_code']) ?></code>)
                        will be marked <strong>Cancelled</strong>. This is a soft delete — their attendance
                        and payment history is preserved, and they can be restored anytime from the
                        Cancelled Members list.
                    </div>

                    <form method="POST" action="cancel.php" id="cancelForm">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="member_id" value="<?= $member_id ?>">

                        <div class="mb-3">
                            <label class="form-label required-field">Reason for Cancellation</label>
                            <select class="form-select" name="reason" id="reasonSelect" required>
                                <option value="">Select a reason</option>
                                <?php foreach ($reasons as $r): ?>
                                    <option value="<?= h($r) ?>"><?= h($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Optional details…"></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="<?= base_url('members/view.php?id=' . $member_id) ?>" class="btn btn-outline-secondary">Back</a>
                            <button type="submit" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#confirmModal" type="button" id="openConfirm">
                                <i class="bi bi-x-circle-fill me-1"></i> Cancel Membership
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Confirm Cancellation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to cancel <strong><?= h($member['member_name']) ?></strong>'s membership?
                They will lose gym access until restored.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Go Back</button>
                <button type="button" class="btn btn-warning" id="confirmCancelBtn">Yes, Cancel It</button>
            </div>
        </div>
    </div>
</div>

<script>
// Intercept submit to show the confirmation modal first
const form = document.getElementById('cancelForm');
const openBtn = document.getElementById('openConfirm');
const modalEl = document.getElementById('confirmModal');
const modal = new bootstrap.Modal(modalEl);

openBtn.addEventListener('click', function (e) {
    e.preventDefault();
    const reason = document.getElementById('reasonSelect').value;
    if (!reason) {
        document.getElementById('reasonSelect').reportValidity();
        return;
    }
    modal.show();
});

document.getElementById('confirmCancelBtn').addEventListener('click', function () {
    form.submit();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
