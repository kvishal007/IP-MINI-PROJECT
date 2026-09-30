<?php
/**
 * File    : members/view.php
 * Purpose : Read-only member profile — personal details, plan, payment
 *           history, recent attendance, and quick links to edit / renew /
 *           cancel / the full analyzer report card.
 * Module  : Module 1 (Registration) support view
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo       = get_db();
$member_id = (int) ($_GET['id'] ?? 0);

if ($member_id <= 0) {
    flash('error', 'Invalid member selected.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

// Member + plan + trainer + computed attendance % (from the view)
$stmt = $pdo->prepare('SELECT * FROM v_member_summary WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$member = $stmt->fetch();

if (!$member) {
    flash('error', 'Member not found.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

$page_title = $member['member_name'];

// Payment history, most recent first
$stmt = $pdo->prepare(
    'SELECT pay.*, p.plan_name
     FROM payment pay
     JOIN plan p ON p.plan_id = pay.plan_id
     WHERE pay.member_id = :id
     ORDER BY pay.paid_date DESC, pay.payment_id DESC'
);
$stmt->execute([':id' => $member_id]);
$payments      = $stmt->fetchAll();
$total_paid    = array_sum(array_column($payments, 'amount'));

// Last 10 attendance rows
$stmt = $pdo->prepare(
    'SELECT * FROM attendance WHERE member_id = :id
     ORDER BY att_date DESC LIMIT 10'
);
$stmt->execute([':id' => $member_id]);
$recent_attendance = $stmt->fetchAll();

$pct  = (float) ($member['attendance_pct'] ?? 0);
$band = attendance_band($pct);
$risk = dropout_risk(
    $member['days_since_visit'] !== null ? (int) $member['days_since_visit'] : null,
    $member['status']
);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">
                <?= h($member['member_name']) ?>
                <?= status_badge($member['status']) ?>
            </h2>
            <p class="text-muted mb-0"><code><?= h($member['member_code']) ?></code></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('reports/member_report.php?id=' . $member_id) ?>" class="btn btn-outline-primary">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i> Full Report Card
            </a>
            <a href="<?= base_url('members/edit.php?id=' . $member_id) ?>" class="btn btn-primary">
                <i class="bi bi-pencil-fill me-1"></i> Edit / Renew
            </a>
            <?php if ($member['status'] !== 'Cancelled'): ?>
            <a href="<?= base_url('members/cancel.php?id=' . $member_id) ?>" class="btn btn-outline-warning">
                <i class="bi bi-x-circle-fill me-1"></i> Cancel
            </a>
            <?php endif; ?>
            <a href="<?= base_url('members/list.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Profile Card -->
        <div class="col-12 col-lg-4">
            <div class="form-card">
                <div class="form-card-header"><i class="bi bi-person-vcard-fill me-2 text-accent"></i>Profile</div>
                <div class="form-card-body">
                    <table class="table table-borderless table-sm mb-0 profile-table">
                        <tr><th>Gender</th><td><?= h($member['gender']) ?></td></tr>
                        <tr><th>Date of Birth</th><td><?= fmt_date($member['dob']) ?></td></tr>
                        <tr><th>Phone</th><td><?= h($member['phone']) ?></td></tr>
                        <tr><th>Email</th><td><?= h($member['email'] ?: '—') ?></td></tr>
                        <tr><th>Join Date</th><td><?= fmt_date($member['join_date']) ?></td></tr>
                        <tr><th>Expiry Date</th><td><?= fmt_date($member['expiry_date']) ?></td></tr>
                        <tr><th>Plan</th><td><?= h($member['plan_name']) ?> (₹<?= number_format($member['fee'], 0) ?>)</td></tr>
                        <tr><th>Trainer</th><td><?= h($member['trainer_name'] ?? 'Unassigned') ?></td></tr>
                        <?php if ($member['status'] === 'Cancelled'): ?>
                        <tr><th>Cancelled On</th><td><?= fmt_date($member['cancel_date']) ?></td></tr>
                        <tr><th>Reason</th><td><?= h($member['cancel_reason'] ?: '—') ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Attendance Snapshot -->
        <div class="col-12 col-lg-4">
            <div class="form-card">
                <div class="form-card-header"><i class="bi bi-graph-up-arrow me-2 text-accent"></i>Attendance Snapshot</div>
                <div class="form-card-body text-center">
                    <div class="display-5 fw-bold text-<?= $band['class'] ?>"><?= $pct ?>%</div>
                    <span class="badge bg-<?= $band['class'] ?> mb-3"><?= $band['label'] ?></span>
                    <table class="table table-borderless table-sm mb-0 profile-table text-start">
                        <tr><th>Last Visit</th><td><?= fmt_date($member['last_visit']) ?></td></tr>
                        <tr><th>Days Since Visit</th><td><?= h($member['days_since_visit'] ?? '—') ?></td></tr>
                        <?php if ($risk !== ''): ?>
                        <tr><th>Risk</th><td><span class="badge bg-<?= $risk === 'Likely Dropout' ? 'danger' : 'warning text-dark' ?>"><?= h($risk) ?></span></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="form-card mt-4">
                <div class="form-card-header"><i class="bi bi-clock-history me-2 text-accent"></i>Recent Attendance</div>
                <div class="form-card-body p-0">
                    <?php if (empty($recent_attendance)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-calendar-x fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No attendance recorded yet.</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>In</th><th>Out</th><th>Duration</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent_attendance as $a): ?>
                                <tr>
                                    <td><?= fmt_date($a['att_date']) ?></td>
                                    <td><?= fmt_time($a['check_in']) ?></td>
                                    <td><?= fmt_time($a['check_out']) ?></td>
                                    <td><?= $a['duration_min'] !== null ? $a['duration_min'] . ' min' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="col-12 col-lg-4">
            <div class="form-card">
                <div class="form-card-header d-flex justify-content-between">
                    <span><i class="bi bi-receipt me-2 text-accent"></i>Payment History</span>
                    <span class="badge bg-accent">₹<?= number_format($total_paid, 0) ?> total</span>
                </div>
                <div class="form-card-body p-0">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-cash-stack fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No payments recorded.</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Mode</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= fmt_date($p['paid_date']) ?></td>
                                    <td><?= h($p['plan_name']) ?></td>
                                    <td>₹<?= number_format($p['amount'], 0) ?></td>
                                    <td><span class="badge bg-secondary"><?= h($p['mode']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
