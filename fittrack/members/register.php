<?php
/**
 * File    : members/register.php
 * Purpose : Register a new gym member (INSERT). Auto-generates member code,
 *           computes expiry, inserts first payment row. Full server-side validation.
 * Module  : Module 1 — Registration (INSERT)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = get_db();
$page_title = 'Register Member';
$errors   = [];
$success  = '';
$old      = $_POST; // repopulate form on validation failure

// Load plans and trainers for dropdowns
$plans    = $pdo->query('SELECT * FROM plan ORDER BY fee')->fetchAll();
$trainers = $pdo->query('SELECT * FROM trainer ORDER BY trainer_name')->fetchAll();

// ---------------------------------------------------------------
// Handle POST: Register new member
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    // Collect and sanitize inputs
    $name       = trim($_POST['member_name']     ?? '');
    $gender     = trim($_POST['gender']          ?? '');
    $dob        = trim($_POST['dob']             ?? '');
    $phone      = trim($_POST['phone']           ?? '');
    $email      = trim($_POST['email']           ?? '');
    $address    = trim($_POST['address']         ?? '');
    $emergency  = trim($_POST['emergency_contact']?? '');
    $join_date  = trim($_POST['join_date']       ?? '');
    $plan_id    = (int)($_POST['plan_id']        ?? 0);
    $trainer_id = (int)($_POST['trainer_id']     ?? 0);
    $pay_mode   = trim($_POST['pay_mode']        ?? '');

    // --- Server-side validation ---
    if ($name === '')    $errors[] = 'Member name is required.';
    if (!in_array($gender, ['Male','Female','Other'])) $errors[] = 'Please select a valid gender.';

    if ($dob === '') {
        $errors[] = 'Date of birth is required.';
    } else {
        $age = validate_age($dob);
        if ($age === false) $errors[] = 'Age must be between 12 and 80 years.';
    }

    if (!validate_phone($phone))    $errors[] = 'Phone must be exactly 10 digits.';
    if ($email !== '' && !validate_email($email)) $errors[] = 'Enter a valid email address.';
    if ($join_date === '')          $errors[] = 'Join date is required.';
    if ($plan_id <= 0)              $errors[] = 'Please select a membership plan.';
    if (!in_array($pay_mode, ['Cash','UPI','Card'])) $errors[] = 'Please select a payment mode.';

    if ($emergency !== '' && !validate_phone($emergency)) {
        $errors[] = 'Emergency contact must be exactly 10 digits.';
    }

    if (empty($errors)) {
        if (phone_exists($phone))        $errors[] = 'This phone number is already registered.';
        if (email_exists($email))        $errors[] = 'This email address is already registered.';
    }

    if (empty($errors)) {
        // Fetch plan details
        $stmt = $pdo->prepare('SELECT * FROM plan WHERE plan_id = :pid');
        $stmt->execute([':pid' => $plan_id]);
        $plan = $stmt->fetch();

        if (!$plan) {
            $errors[] = 'Selected plan not found.';
        } else {
            $expiry_date  = compute_expiry($join_date, (int)$plan['duration_months']);
            $member_code  = generate_member_code();
            $trainer_val  = $trainer_id > 0 ? $trainer_id : null;

            try {
                $pdo->beginTransaction();

                // INSERT member
                $stmt = $pdo->prepare(
                    'INSERT INTO member
                        (member_code, member_name, gender, dob, phone, email,
                         address, emergency_contact, join_date, expiry_date,
                         plan_id, trainer_id, status)
                     VALUES
                        (:code, :name, :gender, :dob, :phone, :email,
                         :address, :emergency, :join_date, :expiry,
                         :plan_id, :trainer_id, :status)'
                );
                $stmt->execute([
                    ':code'       => $member_code,
                    ':name'       => $name,
                    ':gender'     => $gender,
                    ':dob'        => $dob,
                    ':phone'      => $phone,
                    ':email'      => $email ?: null,
                    ':address'    => $address ?: null,
                    ':emergency'  => $emergency ?: null,
                    ':join_date'  => $join_date,
                    ':expiry'     => $expiry_date,
                    ':plan_id'    => $plan_id,
                    ':trainer_id' => $trainer_val,
                    ':status'     => 'Active',
                ]);
                $new_member_id = (int) $pdo->lastInsertId();

                // INSERT first payment row
                $stmt = $pdo->prepare(
                    'INSERT INTO payment
                        (member_id, plan_id, amount, paid_date, mode, next_due_date)
                     VALUES
                        (:mid, :pid, :amount, :paid_date, :mode, :next_due)'
                );
                $stmt->execute([
                    ':mid'       => $new_member_id,
                    ':pid'       => $plan_id,
                    ':amount'    => $plan['fee'],
                    ':paid_date' => $join_date,
                    ':mode'      => $pay_mode,
                    ':next_due'  => $expiry_date,
                ]);

                $pdo->commit();
                flash('success', "Member {$member_code} — {$name} registered successfully!");
                header('Location: ' . base_url('members/list.php'));
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="section-heading">Register New Member</h2>
            <p class="text-muted">Module 1 — INSERT Operation</p>
        </div>
        <a href="<?= base_url('members/list.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to List
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Please fix the following errors:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php" id="registerForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <div class="row g-4">
            <!-- Personal Details -->
            <div class="col-12 col-lg-8">
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="bi bi-person-fill me-2 text-accent"></i>Personal Details
                    </div>
                    <div class="form-card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="member_name" class="form-label required-field">Full Name</label>
                                <input type="text" class="form-control" id="member_name" name="member_name"
                                       value="<?= h($old['member_name'] ?? '') ?>"
                                       placeholder="e.g., Aravind Kumar" required>
                            </div>
                            <div class="col-md-3">
                                <label for="gender" class="form-label required-field">Gender</label>
                                <select class="form-select" id="gender" name="gender" required>
                                    <option value="">Select</option>
                                    <?php foreach (['Male','Female','Other'] as $g): ?>
                                        <option value="<?= $g ?>" <?= ($old['gender'] ?? '') === $g ? 'selected' : '' ?>>
                                            <?= $g ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="dob" class="form-label required-field">Date of Birth</label>
                                <input type="date" class="form-control" id="dob" name="dob"
                                       value="<?= h($old['dob'] ?? '') ?>"
                                       max="<?= date('Y-m-d', strtotime('-12 years')) ?>"
                                       min="<?= date('Y-m-d', strtotime('-80 years')) ?>"
                                       required>
                                <div class="form-text" id="ageDisplay"></div>
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label required-field">Phone (10 digits)</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       value="<?= h($old['phone'] ?? '') ?>"
                                       pattern="\d{10}" maxlength="10" placeholder="9876543210" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?= h($old['email'] ?? '') ?>"
                                       placeholder="email@example.com">
                            </div>
                            <div class="col-md-8">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2"
                                          placeholder="Street, City, District"><?= h($old['address'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label for="emergency_contact" class="form-label">Emergency Contact</label>
                                <input type="tel" class="form-control" id="emergency_contact" name="emergency_contact"
                                       value="<?= h($old['emergency_contact'] ?? '') ?>"
                                       pattern="\d{10}" maxlength="10" placeholder="10-digit phone">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Membership & Payment -->
            <div class="col-12 col-lg-4">
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="bi bi-card-list me-2 text-accent"></i>Membership
                    </div>
                    <div class="form-card-body">
                        <div class="mb-3">
                            <label for="join_date" class="form-label required-field">Join Date</label>
                            <input type="date" class="form-control" id="join_date" name="join_date"
                                   value="<?= h($old['join_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="plan_id" class="form-label required-field">Membership Plan</label>
                            <select class="form-select" id="plan_id" name="plan_id" required>
                                <option value="">Select Plan</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= $p['plan_id'] ?>"
                                            data-fee="<?= $p['fee'] ?>"
                                            data-months="<?= $p['duration_months'] ?>"
                                            <?= ($old['plan_id'] ?? 0) == $p['plan_id'] ? 'selected' : '' ?>>
                                        <?= h($p['plan_name']) ?> — ₹<?= number_format($p['fee'], 0) ?>
                                        (<?= $p['duration_months'] ?> month<?= $p['duration_months'] > 1 ? 's' : '' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-2 p-2 bg-light rounded" id="planPreview" style="display:none;">
                                <small class="text-muted">Expiry: <strong id="previewExpiry">—</strong></small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="trainer_id" class="form-label">Assign Trainer</label>
                            <select class="form-select" id="trainer_id" name="trainer_id">
                                <option value="">No Trainer</option>
                                <?php foreach ($trainers as $t): ?>
                                    <option value="<?= $t['trainer_id'] ?>"
                                            <?= ($old['trainer_id'] ?? 0) == $t['trainer_id'] ? 'selected' : '' ?>>
                                        <?= h($t['trainer_name']) ?> (<?= h($t['specialization']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="pay_mode" class="form-label required-field">Payment Mode</label>
                            <select class="form-select" id="pay_mode" name="pay_mode" required>
                                <option value="">Select Mode</option>
                                <?php foreach (['Cash','UPI','Card'] as $m): ?>
                                    <option value="<?= $m ?>" <?= ($old['pay_mode'] ?? '') === $m ? 'selected' : '' ?>>
                                        <?= $m ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="d-grid mt-3">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-person-plus-fill me-2"></i>Register Member
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Show computed age below DOB field
document.getElementById('dob').addEventListener('change', function () {
    const dob = new Date(this.value);
    if (!isNaN(dob)) {
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
        const el = document.getElementById('ageDisplay');
        if (age < 12 || age > 80) {
            el.innerHTML = '<span class="text-danger">Age must be between 12-80 years (current: ' + age + ')</span>';
        } else {
            el.innerHTML = '<span class="text-success">Age: ' + age + ' years</span>';
        }
    }
});

// Show plan expiry preview
document.getElementById('plan_id').addEventListener('change', function () {
    const sel     = this.options[this.selectedIndex];
    const months  = parseInt(sel.dataset.months || 0);
    const joinEl  = document.getElementById('join_date');
    const preview = document.getElementById('planPreview');
    const expEl   = document.getElementById('previewExpiry');

    if (months > 0 && joinEl.value) {
        const join = new Date(joinEl.value);
        join.setMonth(join.getMonth() + months);
        expEl.textContent = join.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
});
document.getElementById('join_date').addEventListener('change', function () {
    document.getElementById('plan_id').dispatchEvent(new Event('change'));
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
