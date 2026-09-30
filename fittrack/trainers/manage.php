<?php
/**
 * File    : trainers/manage.php
 * Purpose : Manage trainers — add (INSERT), edit (UPDATE) and delete
 *           (DELETE). Deleting a trainer sets their members' trainer_id to
 *           NULL via the schema's ON DELETE SET NULL, so members are never
 *           lost — they simply become unassigned.
 * Module  : Module 1 (Registration — trainer master) + Module 3 + Module 5
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = 'Manage Trainers';
$errors     = [];
$edit_id    = (int) ($_GET['edit'] ?? 0);

$specializations = ['Strength', 'Cardio', 'CrossFit', 'Yoga', 'Rehab'];
$shifts          = ['Morning', 'Evening', 'Both'];

// ---------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $name    = trim($_POST['trainer_name']  ?? '');
        $spec    = trim($_POST['specialization'] ?? '');
        $phone   = trim($_POST['phone']          ?? '');
        $shift   = trim($_POST['shift']          ?? '');

        if ($name === '')                        $errors[] = 'Trainer name is required.';
        if (mb_strlen($name) > 40)               $errors[] = 'Trainer name must be 40 characters or fewer.';
        if (!in_array($spec, $specializations, true))  $errors[] = 'Please select a valid specialization.';
        if (!in_array($shift, $shifts, true))          $errors[] = 'Please select a valid shift.';
        if ($phone !== '' && !validate_phone($phone))  $errors[] = 'Phone must be exactly 10 digits.';
    }

    // ---- ADD ----
    if ($action === 'add' && empty($errors)) {
        $stmt = $pdo->prepare(
            'INSERT INTO trainer (trainer_name, specialization, phone, shift)
             VALUES (:n, :s, :p, :sh)'
        );
        $stmt->execute([':n' => $name, ':s' => $spec, ':p' => $phone ?: null, ':sh' => $shift]);
        flash('success', "Trainer \"{$name}\" added successfully.");
        header('Location: ' . base_url('trainers/manage.php'));
        exit;
    }

    // ---- UPDATE ----
    if ($action === 'update' && empty($errors)) {
        $trainer_id = (int) ($_POST['trainer_id'] ?? 0);
        $stmt = $pdo->prepare(
            'UPDATE trainer SET trainer_name=:n, specialization=:s, phone=:p, shift=:sh
             WHERE trainer_id=:id'
        );
        $stmt->execute([
            ':n' => $name, ':s' => $spec, ':p' => $phone ?: null,
            ':sh' => $shift, ':id' => $trainer_id,
        ]);
        flash('success', "Trainer \"{$name}\" updated successfully.");
        header('Location: ' . base_url('trainers/manage.php'));
        exit;
    }

    // ---- DELETE ----
    if ($action === 'delete') {
        $trainer_id = (int) ($_POST['trainer_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM member WHERE trainer_id = :id');
        $stmt->execute([':id' => $trainer_id]);
        $assigned = (int) $stmt->fetchColumn();

        // ON DELETE SET NULL on member.trainer_id means the members survive
        // and simply become unassigned — we just warn about how many.
        $stmt = $pdo->prepare('DELETE FROM trainer WHERE trainer_id = :id');
        $stmt->execute([':id' => $trainer_id]);

        $msg = 'Trainer deleted.';
        if ($assigned > 0) {
            $msg .= " {$assigned} member(s) are now unassigned — please assign them a new trainer.";
        }
        flash('success', $msg);
        header('Location: ' . base_url('trainers/manage.php'));
        exit;
    }
}

// ---------------------------------------------------------------
// Trainer list with load + average attendance of their members
// (Module 4.9 — trainer load analysis, aggregated in SQL)
// ---------------------------------------------------------------
$trainers = $pdo->query(
    "SELECT t.*,
            COUNT(v.member_id) AS member_count,
            SUM(v.status = 'Active') AS active_count,
            ROUND(AVG(v.attendance_pct), 2) AS avg_attendance_pct
     FROM trainer t
     LEFT JOIN v_member_summary v ON v.trainer_id = t.trainer_id
     GROUP BY t.trainer_id, t.trainer_name, t.specialization, t.phone, t.shift
     ORDER BY t.trainer_name"
)->fetchAll();

// Trainer being edited
$edit_trainer = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM trainer WHERE trainer_id = :id');
    $stmt->execute([':id' => $edit_id]);
    $edit_trainer = $stmt->fetch() ?: null;
}

// Members with no trainer assigned
$unassigned = (int) $pdo->query('SELECT COUNT(*) FROM member WHERE trainer_id IS NULL')->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="mb-4">
        <h2 class="section-heading">Manage Trainers</h2>
        <p class="text-muted">Trainer master with load and average member attendance</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($unassigned > 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill me-2"></i>
            <?= $unassigned ?> member(s) currently have no trainer assigned.
            <a href="<?= base_url('members/list.php') ?>" class="alert-link">Review members &raquo;</a>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Add / Edit form -->
        <div class="col-12 col-lg-4">
            <form method="POST" action="manage.php" class="form-card">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $edit_trainer ? 'update' : 'add' ?>">
                <?php if ($edit_trainer): ?>
                    <input type="hidden" name="trainer_id" value="<?= (int)$edit_trainer['trainer_id'] ?>">
                <?php endif; ?>

                <div class="form-card-header">
                    <i class="bi bi-<?= $edit_trainer ? 'pencil-fill' : 'person-plus-fill' ?> me-2 text-accent"></i>
                    <?= $edit_trainer ? 'Edit Trainer' : 'Add New Trainer' ?>
                </div>
                <div class="form-card-body">
                    <div class="mb-3">
                        <label class="form-label required-field">Trainer Name</label>
                        <input type="text" class="form-control" name="trainer_name" maxlength="40" required
                               value="<?= h($edit_trainer['trainer_name'] ?? '') ?>" placeholder="e.g., Arjun Selvam">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-field">Specialization</label>
                        <select class="form-select" name="specialization" required>
                            <option value="">Select</option>
                            <?php foreach ($specializations as $s): ?>
                                <option value="<?= $s ?>" <?= ($edit_trainer['specialization'] ?? '') === $s ? 'selected' : '' ?>>
                                    <?= $s ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone (10 digits)</label>
                        <input type="tel" class="form-control" name="phone" pattern="\d{10}" maxlength="10"
                               value="<?= h($edit_trainer['phone'] ?? '') ?>" placeholder="9876543210">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-field">Shift</label>
                        <select class="form-select" name="shift" required>
                            <option value="">Select</option>
                            <?php foreach ($shifts as $s): ?>
                                <option value="<?= $s ?>" <?= ($edit_trainer['shift'] ?? '') === $s ? 'selected' : '' ?>>
                                    <?= $s ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-save-fill me-1"></i> <?= $edit_trainer ? 'Update Trainer' : 'Add Trainer' ?>
                    </button>
                    <?php if ($edit_trainer): ?>
                        <a href="manage.php" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Trainer table -->
        <div class="col-12 col-lg-8">
            <div class="table-card">
                <?php if (empty($trainers)): ?>
                    <div class="empty-state py-5">
                        <i class="bi bi-person-badge fs-1 text-muted"></i>
                        <h5 class="mt-3">No trainers yet</h5>
                        <p class="text-muted">Add your first trainer using the form.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-head">
                            <tr>
                                <th>Trainer</th><th>Specialization</th><th>Shift</th><th>Phone</th>
                                <th>Members</th><th>Avg Attendance</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($trainers as $t): ?>
                            <?php
                                $avg  = $t['avg_attendance_pct'] !== null ? (float)$t['avg_attendance_pct'] : null;
                                $band = $avg !== null ? attendance_band($avg) : null;
                            ?>
                            <tr>
                                <td><strong><?= h($t['trainer_name']) ?></strong></td>
                                <td><span class="badge bg-secondary"><?= h($t['specialization']) ?></span></td>
                                <td><?= h($t['shift']) ?></td>
                                <td><?= h($t['phone'] ?: '—') ?></td>
                                <td>
                                    <span class="badge bg-primary"><?= (int)$t['member_count'] ?></span>
                                    <span class="small text-muted">(<?= (int)$t['active_count'] ?> active)</span>
                                </td>
                                <td>
                                    <?php if ($avg !== null): ?>
                                        <span class="badge bg-<?= $band['class'] ?>"><?= $avg ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="manage.php?edit=<?= (int)$t['trainer_id'] ?>"
                                           class="btn btn-xs btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <form method="POST" action="manage.php" class="d-inline m-0"
                                              onsubmit="return confirm('Delete trainer &quot;<?= h($t['trainer_name']) ?>&quot;? Their <?= (int)$t['member_count'] ?> member(s) will become unassigned.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="trainer_id" value="<?= (int)$t['trainer_id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete trainer">
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
                <?php endif; ?>
            </div>
            <p class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>
                Deleting a trainer does not delete their members — the schema's
                <code>ON DELETE SET NULL</code> leaves those members unassigned.
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
