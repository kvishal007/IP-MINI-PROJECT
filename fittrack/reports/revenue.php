<?php
/**
 * File    : reports/revenue.php
 * Purpose : Revenue report — collections by month, by plan and by payment
 *           mode, plus the individual payment ledger for a chosen range.
 *           All figures come from SQL aggregation over the payment table.
 * Module  : Module 4 — Analyzer / Reports (revenue analysis)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo        = get_db();
$page_title = 'Revenue Report';

// ---------------------------------------------------------------
// Date-range filter (defaults to the last 6 months)
// ---------------------------------------------------------------
$from_date = trim($_GET['from'] ?? date('Y-m-01', strtotime('-5 months')));
$to_date   = trim($_GET['to']   ?? date('Y-m-d'));
$mode_f    = trim($_GET['mode'] ?? '');

$where  = ['pay.paid_date BETWEEN :from AND :to'];
$params = [':from' => $from_date, ':to' => $to_date];
if (in_array($mode_f, ['Cash','UPI','Card'], true)) {
    $where[]         = 'pay.mode = :mode';
    $params[':mode'] = $mode_f;
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// ---------------------------------------------------------------
// Headline totals for the range
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(pay.amount), 0)  AS total_revenue,
            COUNT(*)                      AS payment_count,
            COUNT(DISTINCT pay.member_id) AS paying_members,
            COALESCE(ROUND(AVG(pay.amount), 2), 0) AS avg_payment
     FROM payment pay $where_sql"
);
$stmt->execute($params);
$totals = $stmt->fetch();

// This calendar month, for comparison against the filtered range
$this_month = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM payment
     WHERE MONTH(paid_date) = MONTH(CURDATE()) AND YEAR(paid_date) = YEAR(CURDATE())"
)->fetchColumn();

// ---------------------------------------------------------------
// Month-by-month collections (DATE_FORMAT on MIN() keeps the label an
// aggregate, which ONLY_FULL_GROUP_BY requires)
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(MIN(pay.paid_date), '%b %Y') AS month_label,
            SUM(pay.amount)  AS revenue,
            COUNT(*)         AS payments
     FROM payment pay $where_sql
     GROUP BY YEAR(pay.paid_date), MONTH(pay.paid_date)
     ORDER BY YEAR(pay.paid_date), MONTH(pay.paid_date)"
);
$stmt->execute($params);
$monthly_rows   = $stmt->fetchAll();
$monthly_labels = array_column($monthly_rows, 'month_label');
$monthly_data   = array_map('floatval', array_column($monthly_rows, 'revenue'));

// ---------------------------------------------------------------
// Plan-wise revenue for the range
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT p.plan_name, p.fee,
            COUNT(*)        AS payments,
            SUM(pay.amount) AS revenue
     FROM payment pay
     JOIN plan p ON p.plan_id = pay.plan_id
     $where_sql
     GROUP BY p.plan_id, p.plan_name, p.fee
     ORDER BY revenue DESC"
);
$stmt->execute($params);
$plan_rows    = $stmt->fetchAll();
$plan_labels  = array_column($plan_rows, 'plan_name');
$plan_revenue = array_map('floatval', array_column($plan_rows, 'revenue'));

// ---------------------------------------------------------------
// Payment-mode split for the range
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT pay.mode, COUNT(*) AS payments, SUM(pay.amount) AS revenue
     FROM payment pay $where_sql
     GROUP BY pay.mode
     ORDER BY revenue DESC"
);
$stmt->execute($params);
$mode_rows = $stmt->fetchAll();

// ---------------------------------------------------------------
// Payment ledger (most recent 100 in the range)
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT pay.payment_id, pay.paid_date, pay.amount, pay.mode, pay.next_due_date,
            m.member_id, m.member_code, m.member_name, p.plan_name
     FROM payment pay
     JOIN member m ON m.member_id = pay.member_id
     JOIN plan   p ON p.plan_id   = pay.plan_id
     $where_sql
     ORDER BY pay.paid_date DESC, pay.payment_id DESC
     LIMIT 100"
);
$stmt->execute($params);
$ledger = $stmt->fetchAll();

$filter_qs = http_build_query(['from' => $from_date, 'to' => $to_date, 'mode' => $mode_f]);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="section-heading">Revenue Report</h2>
            <p class="text-muted mb-0"><?= fmt_date($from_date) ?> to <?= fmt_date($to_date) ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= base_url('reports/export_csv.php?report=revenue&' . $filter_qs) ?>" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="bi bi-printer-fill me-1"></i> Print
            </button>
            <a href="<?= base_url('reports/analyzer.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-bar-chart-line-fill me-1"></i> Analyzer
            </a>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="revenue.php" class="filter-bar mb-4 no-print">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted">From</label>
                <input type="date" class="form-control" name="from" value="<?= h($from_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted">To</label>
                <input type="date" class="form-control" name="to" value="<?= h($to_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted">Payment Mode</label>
                <select class="form-select" name="mode">
                    <option value="">All Modes</option>
                    <?php foreach (['Cash','UPI','Card'] as $md): ?>
                        <option value="<?= $md ?>" <?= $mode_f === $md ? 'selected' : '' ?>><?= $md ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Apply</button>
                <a href="revenue.php" class="btn btn-outline-secondary flex-fill">Reset</a>
            </div>
        </div>
    </form>

    <!-- KPI tiles -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-accent">
                <div class="kpi-icon"><i class="bi bi-currency-rupee"></i></div>
                <div class="kpi-value">₹<?= number_format((float)$totals['total_revenue'], 0) ?></div>
                <div class="kpi-label">Revenue in Range</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon"><i class="bi bi-receipt"></i></div>
                <div class="kpi-value"><?= (int)$totals['payment_count'] ?></div>
                <div class="kpi-label">Payments</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-value"><?= (int)$totals['paying_members'] ?></div>
                <div class="kpi-label">Paying Members</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon"><i class="bi bi-calendar-month-fill"></i></div>
                <div class="kpi-value">₹<?= number_format($this_month, 0) ?></div>
                <div class="kpi-label">This Month</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Monthly collections chart -->
        <div class="col-12 col-xl-7">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Monthly Collections</h5>
                        <p class="chart-subtitle text-muted small mb-0">Total payments received per month</p>
                    </div>
                </div>
                <div class="chart-body">
                    <?php if (empty($monthly_data)): ?>
                        <div class="empty-state py-4"><i class="bi bi-cash-stack fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No payments in this range.</p></div>
                    <?php else: ?>
                        <canvas id="revenueChart" height="120"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Plan-wise revenue doughnut -->
        <div class="col-12 col-xl-5">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h5 class="chart-title">Revenue by Plan</h5>
                        <p class="chart-subtitle text-muted small mb-0">Share of collections per plan</p>
                    </div>
                </div>
                <div class="chart-body d-flex justify-content-center">
                    <?php if (empty($plan_revenue)): ?>
                        <div class="empty-state py-4"><i class="bi bi-pie-chart fs-2 text-muted"></i>
                            <p class="mt-2 mb-0">No payments in this range.</p></div>
                    <?php else: ?>
                        <canvas id="planRevenueChart" style="max-height:250px;max-width:250px;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Plan-wise table -->
        <div class="col-12 col-lg-7">
            <div class="table-card">
                <div class="p-3 border-bottom"><h6 class="mb-0">Plan-wise Revenue</h6></div>
                <?php if (empty($plan_rows)): ?>
                    <div class="empty-state py-4"><p class="mb-0 text-muted">No payments in this range.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-head"><tr><th>Plan</th><th>Fee</th><th>Payments</th><th>Revenue</th><th>Share</th></tr></thead>
                        <tbody>
                        <?php foreach ($plan_rows as $p):
                            $share = $totals['total_revenue'] > 0 ? ($p['revenue'] / $totals['total_revenue']) * 100 : 0; ?>
                            <tr>
                                <td><strong><?= h($p['plan_name']) ?></strong></td>
                                <td>₹<?= number_format($p['fee'], 0) ?></td>
                                <td><?= (int)$p['payments'] ?></td>
                                <td>₹<?= number_format($p['revenue'], 0) ?></td>
                                <td style="min-width:120px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-fill" style="height:6px;">
                                            <div class="progress-bar bg-accent" style="width:<?= round($share, 1) ?>%"></div>
                                        </div>
                                        <span class="small"><?= number_format($share, 1) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr><td colspan="3" class="text-end">Total</td>
                                <td>₹<?= number_format((float)$totals['total_revenue'], 0) ?></td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mode-wise table -->
        <div class="col-12 col-lg-5">
            <div class="table-card">
                <div class="p-3 border-bottom"><h6 class="mb-0">Payment Mode Split</h6></div>
                <?php if (empty($mode_rows)): ?>
                    <div class="empty-state py-4"><p class="mb-0 text-muted">No payments in this range.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-head"><tr><th>Mode</th><th>Payments</th><th>Revenue</th><th>Share</th></tr></thead>
                        <tbody>
                        <?php foreach ($mode_rows as $md):
                            $share = $totals['total_revenue'] > 0 ? ($md['revenue'] / $totals['total_revenue']) * 100 : 0; ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= h($md['mode']) ?></span></td>
                                <td><?= (int)$md['payments'] ?></td>
                                <td>₹<?= number_format($md['revenue'], 0) ?></td>
                                <td><?= number_format($share, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment ledger -->
    <div class="table-card">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Payment Ledger</h6>
            <span class="text-muted small">Showing up to 100 most recent payments in range</span>
        </div>
        <?php if (empty($ledger)): ?>
            <div class="empty-state py-5">
                <i class="bi bi-receipt fs-1 text-muted"></i>
                <h5 class="mt-3">No payments in this date range</h5>
                <p class="text-muted">Try widening the range or clearing the mode filter.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-head">
                    <tr><th>#</th><th>Paid Date</th><th>Code</th><th>Member</th>
                        <th>Plan</th><th>Amount</th><th>Mode</th><th>Next Due</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ledger as $i => $r): ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td><?= fmt_date($r['paid_date']) ?></td>
                        <td><code><?= h($r['member_code']) ?></code></td>
                        <td><a href="<?= base_url('members/view.php?id=' . $r['member_id']) ?>" class="text-decoration-none"><?= h($r['member_name']) ?></a></td>
                        <td><?= h($r['plan_name']) ?></td>
                        <td>₹<?= number_format($r['amount'], 0) ?></td>
                        <td><span class="badge bg-secondary"><?= h($r['mode']) ?></span></td>
                        <td><?= fmt_date($r['next_due_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
window.FT = window.FT || {};
window.FT.revLabels     = <?= json_encode($monthly_labels) ?>;
window.FT.revData       = <?= json_encode($monthly_data) ?>;
window.FT.planRevLabels = <?= json_encode($plan_labels) ?>;
window.FT.planRevData   = <?= json_encode($plan_revenue) ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
