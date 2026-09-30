<?php
/**
 * File    : members/edit.php
 * Purpose : Edit a member's personal details, renew their membership
 *           (plan + payment + expiry extension), reassign their trainer,
 *           and restore a cancelled member. Four small forms, one page.
 * Module  : Module 3 — Update / Renewal (UPDATE)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo       = get_db();
$member_id = (int) ($_GET['id'] ?? $_POST['member_id'] ?? 0);

if ($member_id <= 0) {
    flash('error', 'Invalid member selected.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

$errors  = [];

// ---------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------
    // ACTION 1: Update personal details
    // -------------------------------------------------------
    if ($action === 'update_details') {
        $name      = trim($_POST['member_name']      ?? '');
        $gender    = trim($_POST['gender']            ?? '');
        $dob       = trim($_POST['dob']               ?? '');
        $phone     = trim($_POST['phone']              ?? '');
        $email     = trim($_POST['email']              ?? '');
        $address   = trim($_POST['address']            ?? '');
        $emergency = trim($_POST['emergency_contact']  ?? '');

        if ($name === '') $errors[] = 'Member name is required.';
        if (!in_array($gender, ['Male','Female','Other'])) $errors[] = 'Please select a valid gender.';
        if ($dob === '' || validate_age($dob) === false) $errors[] = 'Age must be between 12 and 80 years.';
        if (!validate_phone($phone)) $errors[] = 'Phone must be exactly 10 digits.';
        if ($email !== '' && !validate_email($email)) $errors[] = 'Enter a valid email address.';
        if ($emergency !== '' && !validate_phone($emergency)) $errors[] = 'Emergency contact must be exactly 10 digits.';

        if (empty($errors) && phone_exists($phone, $member_id)) {
            $errors[] = 'This phone number is already used by another member.';
        }
        if (empty($errors) && email_exists($email, $member_id)) {
            $errors[] = 'This email address is already used by another member.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                'UPDATE member SET member_name=:name, gender=:gender, dob=:dob,
                    phone=:phone, email=:email, address=:address,
                    emergency_contact=:emergency
                 WHERE member_id=:id'
            );
            $stmt->execute([
                ':name' => $name, ':gender' => $gender, ':dob' => $dob,
                ':phone' => $phone, ':email' => $email ?: null,
                ':address' => $address ?: null, ':emergency' => $emergency ?: null,
                ':id' => $member_id,
            ]);
            flash('success', 'Personal details updated successfully.');
            header('Location: ' . base_url("members/edit.php?id={$member_id}"));
            exit;
        }
    }

    // -------------------------------------------------------
    // ACTION 2: Renew membership (Module 3 core operation)
    // -------------------------------------------------------
    elseif ($action === 'renew') {
        $plan_id   = (int) ($_POST['plan_id'] ?? 0);
        $pay_mode  = trim($_POST['pay_mode']  ?? '');
        $paid_date = trim($_POST['paid_date'] ?? date('Y-m-d'));
        $amount    = (float) ($_POST['amount'] ?? 0);

        if ($plan_id <= 0) $errors[] = 'Please select a plan to renew.';
        if (!in_array($pay_mode, ['Cash','UPI','Card'])) $errors[] = 'Please select a payment mode.';
        if ($amount <= 0) $errors[] = 'Payment amount must be greater than zero.';
        if ($paid_date === '') $errors[] = 'Paid date is required.';

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT * FROM plan WHERE plan_id=:pid');
            $stmt->execute([':pid' => $plan_id]);
            $plan = $stmt->fetch();

            $stmt = $pdo->prepare('SELECT expiry_date FROM member WHERE member_id=:id');
            $stmt->execute([':id' => $member_id]);
            $current_expiry = $stmt->fetchColumn();

            if (!$plan || $current_expiry === false) {
                $errors[] = 'Plan or member not found.';
            } else {
                // New expiry = GREATEST(current expiry, today) + plan duration
                $new_expiry = compute_renewal_expiry($current_expiry, (int) $plan['duration_months']);

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'UPDATE member SET plan_id=:pid, expiry_date=:expiry, status=\'Active\',
                            cancel_date=NULL, cancel_reason=NULL
                         WHERE member_id=:id'
                    );
                    $stmt->execute([':pid' => $plan_id, ':expiry' => $new_expiry, ':id' => $member_id]);

                    $stmt = $pdo->prepare(
                        'INSERT INTO payment (member_id, plan_id, amount, paid_date, mode, next_due_date)
                         VALUES (:mid, :pid, :amount, :paid_date, :mode, :next_due)'
                    );
                    $stmt->execute([
                        ':mid' => $member_id, ':pid' => $plan_id, ':amount' => $amount,
                        ':paid_date' => $paid_date, ':mode' => $pay_mode, ':next_due' => $new_expiry,
                    ]);

                    $pdo->commit();
                    flash('success', "Membership renewed! New expiry: " . fmt_date($new_expiry));
                    header('Location: ' . base_url("members/edit.php?id={$member_id}"));
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errors[] = 'Database error while renewing: ' . $e->getMessage();
                }
            }
        }
    }

    // -------------------------------------------------------
    // ACTION 3: Change assigned trainer
    // -------------------------------------------------------
    elseif ($action === 'change_trainer') {
        $trainer_id = (int) ($_POST['trainer_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE member SET trainer_id=:tid WHERE member_id=:id');
        $stmt->execute([':tid' => $trainer_id > 0 ? $trainer_id : null, ':id' => $member_id]);
        flash('success', 'Trainer assignment updated.');
        header('Location: ' . base_url("members/edit.php?id={$member_id}"));
        exit;
    }

    // -------------------------------------------------------
    // ACTION 4: Restore a cancelled member
    // -------------------------------------------------------
    elseif ($action === 'restore') {
        $stmt = $pdo->prepare('SELECT expiry_date FROM member WHERE member_id=:id');
        $stmt->execute([':id' => $member_id]);
        $expiry = $stmt->fetchColumn();
        $new_status = ($expiry !== false && $expiry >= date('Y-m-d')) ? 'Active' : 'Expired';

        $stmt = $pdo->prepare(
            'UPDATE member SET status=:status, cancel_date=NULL, cancel_reason=NULL WHERE member_id=:id'
        );
        $stmt->execute([':status' => $new_status, ':id' => $member_id]);
        flash('success', "Member restored — status set to {$new_status}.");
        header('Location: ' . base_url("members/edit.php?id={$member_id}"));
        exit;
    }
}

// ---------------------------------------------------------------
// Load member + dropdown data for the form
// ---------------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM member WHERE member_id = :id');
$stmt->execute([':id' => $member_id]);
$member = $stmt->fetch();

if (!$member) {
    flash('error', 'Member not found.');
    header('Location: ' . base_url('members/list.php'));
    exit;
}

$page_title = 'Edit ' . $member['member_name'];
$plans      = $pdo->query('SELECT * FROM plan ORDER BY fee')->fetchAll();
$trainers   = $pdo->query('SELECT * FROM trainer ORDER BY trainer_name')->fetchAll();

// Current plan (used to prefill the renewal amount)
$stmt = $pdo->prepare('SELECT * FROM plan WHERE plan_id = :pid');
$stmt->execute([':pid' => $member['plan_id']]);
$current_plan = $stmt->fetch();

$active_tab = $_GET['tab'] ?? 'details';

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">
                Edit <?= h($member['member_name']) ?>
                <?= status_badge($member['status']) ?>
            </h2>
            <p class="text-muted mb-0">
                <code><?= h($member['member_code']) ?></code> · Module 3 — UPDATE Operation
            </p>
        </div>
        <a href="<?= base_url('members/view.php?id=' . $member_id) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Profile
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($member['status'] === 'Cancelled'): ?>
        <div class="alert alert-secondary d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-archive-fill me-2"></i>
                This member was cancelled on <?= fmt_date($member['cancel_date']) ?>
                (<?= h($member['cancel_reason'] ?: 'no reason given') ?>).
            </span>
            <form method="POST" action="edit.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $member_id ?>">
                <input type="hidden" name="action" value="restore">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restore Member
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="editTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $active_tab === 'details' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-details" type="button">
                <i class="bi bi-person-fill me-1"></i> Personal Details
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $active_tab === 'renew' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-renew" type="button">
                <i class="bi bi-arrow-repeat me-1"></i> Renew Membership
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-trainer" type="button">
                <i class="bi bi-person-badge-fill me-1"></i> Trainer
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- TAB 1: Personal Details -->
        <div class="tab-pane fade <?= $active_tab === 'details' ? 'show active' : '' ?>" id="tab-details">
            <form method="POST" action="edit.php" class="form-card">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $member_id ?>">
                <input type="hidden" name="action" value="update_details">
                <div class="form-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-field">Full Name</label>
                            <input type="text" class="form-control" name="member_name" value="<?= h($member['member_name']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Gender</label>
                            <select class="form-select" name="gender" required>
                                <?php foreach (['Male','Female','Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $member['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Date of Birth</label>
                            <input type="date" class="form-control" name="dob" value="<?= h($member['dob']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-field">Phone</label>
                            <input type="tel" class="form-control" name="phone" value="<?= h($member['phone']) ?>" pattern="\d{10}" maxlength="10" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= h($member['email']) ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2"><?= h($member['address']) ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Emergency Contact</label>
                            <input type="tel" class="form-control" name="emergency_contact" value="<?= h($member['emergency_contact']) ?>" pattern="\d{10}" maxlength="10">
                        </div>
                    </div>
                </div>
                <div class="form-card-footer">
                    <button type="submit" class="btn btn-accent"><i class="bi bi-save-fill me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>

        <!-- TAB 2: Renew Membership -->
        <div class="tab-pane fade <?= $active_tab === 'renew' ? 'show active' : '' ?>" id="tab-renew">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <form method="POST" action="edit.php" class="form-card">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="member_id" value="<?= $member_id ?>">
                        <input type="hidden" name="action" value="renew">
                        <div class="form-card-header"><i class="bi bi-arrow-repeat me-2 text-accent"></i>Renew / Change Plan</div>
                        <div class="form-card-body">
                            <p class="text-muted small">
                                Current plan: <strong><?= h($current_plan['plan_name'] ?? '—') ?></strong> ·
                                Expiry: <strong><?= fmt_date($member['expiry_date']) ?></strong>
                            </p>
                            <div class="mb-3">
                                <label class="form-label required-field">Plan</label>
                                <select class="form-select" name="plan_id" id="renewPlanSelect" required>
                                    <?php foreach ($plans as $p): ?>
                                        <option value="<?= $p['plan_id'] ?>" data-fee="<?= $p['fee'] ?>"
                                            <?= (int)$member['plan_id'] === (int)$p['plan_id'] ? 'selected' : '' ?>>
                                            <?= h($p['plan_name']) ?> — ₹<?= number_format($p['fee'], 0) ?> (<?= $p['duration_months'] ?> mo)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label required-field">Amount Paid</label>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="renewAmount" name="amount"
                                           value="<?= h($current_plan['fee'] ?? '') ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label required-field">Payment Mode</label>
                                    <select class="form-select" name="pay_mode" required>
                                        <option value="">Select</option>
                                        <?php foreach (['Cash','UPI','Card'] as $m): ?>
                                            <option value="<?= $m ?>"><?= $m ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label required-field">Paid Date</label>
                                    <input type="date" class="form-control" name="paid_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-card-footer">
                            <button type="submit" class="btn btn-accent"><i class="bi bi-cash-coin me-1"></i> Record Renewal</button>
                        </div>
                    </form>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="form-card h-100">
                        <div class="form-card-header"><i class="bi bi-info-circle-fill me-2 text-accent"></i>How Renewal Works</div>
                        <div class="form-card-body">
                            <p class="mb-2">New expiry date is computed as:</p>
                            <pre class="bg-light p-2 rounded small mb-3">GREATEST(current_expiry, today) + plan_duration_months</pre>
                            <p class="text-muted small mb-0">
                                This means renewing early simply extends from the existing expiry date
                                (no lost days), while renewing after expiry starts the new period from
                                today. The member's status is automatically set back to <strong>Active</strong>.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: Trainer -->
        <div class="tab-pane fade" id="tab-trainer">
            <form method="POST" action="edit.php" class="form-card" style="max-width:480px;">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $member_id ?>">
                <input type="hidden" name="action" value="change_trainer">
                <div class="form-card-header"><i class="bi bi-person-badge-fill me-2 text-accent"></i>Assigned Trainer</div>
                <div class="form-card-body">
                    <label class="form-label">Trainer</label>
                    <select class="form-select" name="trainer_id">
                        <option value="0">No Trainer</option>
                        <?php foreach ($trainers as $t): ?>
                            <option value="<?= $t['trainer_id'] ?>" <?= (int)$member['trainer_id'] === (int)$t['trainer_id'] ? 'selected' : '' ?>>
                                <?= h($t['trainer_name']) ?> (<?= h($t['specialization']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-card-footer">
                    <button type="submit" class="btn btn-accent"><i class="bi bi-save-fill me-1"></i> Update Trainer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-fill renewal amount when plan changes
document.getElementById('renewPlanSelect')?.addEventListener('change', function () {
    const fee = this.options[this.selectedIndex].dataset.fee;
    document.getElementById('renewAmount').value = fee;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
