<?php
/**
 * File    : plans/manage.php
 * Purpose : Manage membership plans — add a new plan (INSERT), edit an
 *           existing plan's fee / duration (UPDATE), and delete a plan
 *           (DELETE) but only when no member is using it.
 * Module  : Module 1 (Registration — plan master) + Module 3 (UPDATE)
 *           + Module 5 (guarded DELETE)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = 'Manage Plans';
$errors     = [];
$edit_id    = (int) ($_GET['edit'] ?? 0);

// ---------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    // Shared field collection + validation for add/update
    if ($action === 'add' || $action === 'update') {
        $plan_name = trim($_POST['plan_name'] ?? '');
        $months    = (int) ($_POST['duration_months'] ?? 0);
        $fee       = (float) ($_POST['fee'] ?? 0);
        $sessions  = (int) ($_POST['sessions_per_week'] ?? 6);
        $descr     = trim($_POST['description'] ?? '');

        if ($plan_name === '')              $errors[] = 'Plan name is required.';
        if (mb_strlen($plan_name) > 30)     $errors[] = 'Plan name must be 30 characters or fewer.';
        if ($months < 1 || $months > 60)    $errors[] = 'Duration must be between 1 and 60 months.';
        if ($fee <= 0)                      $errors[] = 'Fee must be greater than zero.';
        if ($sessions < 1 || $sessions > 7) $errors[] = 'Sessions per week must be between 1 and 7.';
    }

    // ---- ADD a new plan ----
    if ($action === 'add' && empty($errors)) {
        // plan_name is UNIQUE in the schema — check first for a friendly message
        $stmt = $pdo->prepare('SELECT 1 FROM plan WHERE plan_name = :n LIMIT 1');
        $stmt->execute([':n' => $plan_name]);
        if ($stmt->fetchColumn()) {
            $errors[] = "A plan named \"{$plan_name}\" already exists.";
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO plan (plan_name, duration_months, fee, sessions_per_week, description)
                 VALUES (:n, :m, :f, :s, :d)'
            );
            $stmt->execute([
                ':n' => $plan_name, ':m' => $months, ':f' => $fee,
                ':s' => $sessions,  ':d' => $descr ?: null,
            ]);
            flash('success', "Plan \"{$plan_name}\" added successfully.");
            header('Location: ' . base_url('plans/manage.php'));
            exit;
        }
    }

    // ---- UPDATE an existing plan ----
    if ($action === 'update' && empty($errors)) {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT 1 FROM plan WHERE plan_name = :n AND plan_id <> :id LIMIT 1');
        $stmt->execute([':n' => $plan_name, ':id' => $plan_id]);
        if ($stmt->fetchColumn()) {
            $errors[] = "Another plan is already named \"{$plan_name}\".";
        } else {
            $stmt = $pdo->prepare(
                'UPDATE plan SET plan_name=:n, duration_months=:m, fee=:f,
                    sessions_per_week=:s, description=:d
                 WHERE plan_id=:id'
            );
            $stmt->execute([
                ':n' => $plan_name, ':m' => $months, ':f' => $fee,
                ':s' => $sessions,  ':d' => $descr ?: null, ':id' => $plan_id,
            ]);
            flash('success', "Plan \"{$plan_name}\" updated. New fee and duration apply to future registrations and renewals.");
            header('Location: ' . base_url('plans/manage.php'));
            exit;
        }
    }

    // ---- DELETE a plan (only if unused) ----
    if ($action === 'delete') {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);

        // Refuse if any member is on this plan, or any payment references it.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM member WHERE plan_id = :id');
        $stmt->execute([':id' => $plan_id]);
        $member_count = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment WHERE plan_id = :id');
        $stmt->execute([':id' => $plan_id]);
        $payment_count = (int) $stmt->fetchColumn();

        if ($member_count > 0) {
            flash('error', "Cannot delete this plan — {$member_count} member(s) are currently on it. "
                . 'Move those members to another plan first.');
        } elseif ($payment_count > 0) {
            flash('error', "Cannot delete this plan — {$payment_count} payment record(s) reference it. "
                . 'Deleting it would break the revenue history.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM plan WHERE plan_id = :id');
            $stmt->execute([':id' => $plan_id]);
            flash('success', 'Plan deleted successfully.');
        }
        header('Location: ' . base_url('plans/manage.php'));
        exit;
    }
}

// ---------------------------------------------------------------
// Plan list with usage counts (aggregated in SQL, one query)
// ---------------------------------------------------------------
$plans = $pdo->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM member  m WHERE m.plan_id  = p.plan_id) AS member_count,
            (SELECT COUNT(*) FROM payment y WHERE y.plan_id  = p.plan_id) AS payment_count,
            (SELECT COALESCE(SUM(y.amount),0) FROM payment y WHERE y.plan_id = p.plan_id) AS revenue
     FROM plan p
     ORDER BY p.duration_months, p.fee"
)->fetchAll();

// Plan being edited (prefills the form)
$edit_plan = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM plan WHERE plan_id = :id');
    $stmt->execute([':id' => $edit_id]);
    $edit_plan = $stmt->fetch() ?: null;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="mb-4">
        <h2 class="section-heading">Manage Plans</h2>
        <p class="text-muted">Membership plan master — add, edit fee/duration, or remove unused plans</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Add / Edit form -->
        <div class="col-12 col-lg-4">
            <form method="POST" action="manage.php" class="form-card">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $edit_plan ? 'update' : 'add' ?>">
                <?php if ($edit_plan): ?>
                    <input type="hidden" name="plan_id" value="<?= (int)$edit_plan['plan_id'] ?>">
                <?php endif; ?>

                <div class="form-card-header">
                    <i class="bi bi-<?= $edit_plan ? 'pencil-fill' : 'plus-circle-fill' ?> me-2 text-accent"></i>
                    <?= $edit_plan ? 'Edit Plan' : 'Add New Plan' ?>
                </div>
                <div class="form-card-body">
                    <div class="mb-3">
                        <label class="form-label required-field">Plan Name</label>
                        <input type="text" class="form-control" name="plan_name" maxlength="30" required
                               value="<?= h($edit_plan['plan_name'] ?? '') ?>" placeholder="e.g., Quarterly">
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label required-field">Duration (months)</label>
                            <input type="number" class="form-control" name="duration_months" min="1" max="60" required
                                   value="<?= h($edit_plan['duration_months'] ?? 1) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label required-field">Fee (₹)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="fee" required
                                   value="<?= h($edit_plan['fee'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label required-field">Sessions / week</label>
                            <input type="number" class="form-control" name="sessions_per_week" min="1" max="7" required
                                   value="<?= h($edit_plan['sessions_per_week'] ?? 6) ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" maxlength="100"
                                  placeholder="Short description shown on the registration form"><?= h($edit_plan['description'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="form-card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-save-fill me-1"></i> <?= $edit_plan ? 'Update Plan' : 'Add Plan' ?>
                    </button>
                    <?php if ($edit_plan): ?>
                        <a href="manage.php" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Plan table -->
        <div class="col-12 col-lg-8">
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-head">
                            <tr>
                                <th>Plan</th><th>Duration</th><th>Fee</th><th>Sess/wk</th>
                                <th>Members</th><th>Revenue</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($plans as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= h($p['plan_name']) ?></strong>
                                    <?php if ($p['description']): ?>
                                        <div class="small text-muted"><?= h($p['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$p['duration_months'] ?> mo</td>
                                <td>₹<?= number_format($p['fee'], 0) ?></td>
                                <td><?= (int)$p['sessions_per_week'] ?></td>
                                <td>
                                    <span class="badge bg-<?= $p['member_count'] > 0 ? 'primary' : 'secondary' ?>">
                                        <?= (int)$p['member_count'] ?>
                                    </span>
                                </td>
                                <td>₹<?= number_format($p['revenue'], 0) ?></td>
                                <td>
                                    <div class="action-btns">
                                        <a href="manage.php?edit=<?= (int)$p['plan_id'] ?>"
                                           class="btn btn-xs btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <?php $deletable = $p['member_count'] == 0 && $p['payment_count'] == 0; ?>
                                        <form method="POST" action="manage.php" class="d-inline m-0"
                                              onsubmit="return confirm('Delete the plan &quot;<?= h($p['plan_name']) ?>&quot;?');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="plan_id" value="<?= (int)$p['plan_id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-outline-danger"
                                                    <?= $deletable ? '' : 'disabled' ?>
                                                    title="<?= $deletable ? 'Delete plan' : 'In use — cannot delete' ?>">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>
                A plan can only be deleted when no member is enrolled on it and no payment
                references it — this protects the revenue history.
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
