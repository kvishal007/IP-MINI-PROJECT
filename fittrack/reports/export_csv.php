<?php
/**
 * File    : reports/export_csv.php
 * Purpose : CSV download endpoint (Module 4.13). Every report table in the
 *           analyzer can be exported here. The report to build is chosen by
 *           ?report=… and the same filters used on-screen are re-applied so
 *           the file matches exactly what the user was looking at.
 *
 *           Supported reports:
 *             members        — full member list with attendance %
 *             attendance_pct — attendance percentage + band per member
 *             dropout_risk   — At Risk / Likely Dropout list
 *             renewals       — expiring in 7 days + already expired
 *             attendance     — raw attendance rows for a date range
 *             revenue        — payment ledger for a date range
 *             plans          — plan-wise members and revenue
 *             trainers       — trainer load and average attendance
 *
 * Module  : Module 4 — Analyzer / Reports (export)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo    = get_db();
$report = trim($_GET['report'] ?? 'members');

// Shared filters
$from_date = trim($_GET['from']    ?? date('Y-m-d', strtotime('-90 days')));
$to_date   = trim($_GET['to']      ?? date('Y-m-d'));
$plan_f    = (int) ($_GET['plan']    ?? 0);
$trainer_f = (int) ($_GET['trainer'] ?? 0);
$band_f    = trim($_GET['band']    ?? '');
$member_f  = (int) ($_GET['member_id'] ?? 0);
$mode_f    = trim($_GET['mode']    ?? '');

$stamp = date('Y-m-d');

switch ($report) {

    // -----------------------------------------------------------
    // Attendance percentage per member (+ band) — honours plan,
    // trainer and band filters from the analyzer screen
    // -----------------------------------------------------------
    case 'attendance_pct':
    case 'members':
        $where  = [];
        $params = [];
        if ($plan_f > 0)    { $where[] = 'v.plan_id = :plan_id';       $params[':plan_id'] = $plan_f; }
        if ($trainer_f > 0) { $where[] = 'v.trainer_id = :trainer_id'; $params[':trainer_id'] = $trainer_f; }
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $having = '';
        if ($band_f === 'Excellent')     { $having = 'HAVING v.attendance_pct >= 75'; }
        elseif ($band_f === 'Good')      { $having = 'HAVING v.attendance_pct >= 50 AND v.attendance_pct < 75'; }
        elseif ($band_f === 'Poor')      { $having = 'HAVING v.attendance_pct >= 25 AND v.attendance_pct < 50'; }
        elseif ($band_f === 'Critical')  { $having = 'HAVING v.attendance_pct < 25'; }

        $stmt = $pdo->prepare(
            "SELECT v.member_code, v.member_name, v.gender, v.phone, v.email,
                    v.plan_name, v.trainer_name, v.join_date, v.expiry_date,
                    v.status, v.attendance_pct, v.last_visit, v.days_since_visit
             FROM v_member_summary v
             $where_sql
             $having
             ORDER BY v.attendance_pct DESC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $pct = (float) $r['attendance_pct'];
            $rows[] = [
                $r['member_code'], $r['member_name'], $r['gender'], $r['phone'],
                $r['email'] ?? '', $r['plan_name'], $r['trainer_name'] ?? 'Unassigned',
                $r['join_date'], $r['expiry_date'], $r['status'],
                number_format($pct, 2, '.', ''), attendance_band($pct)['label'],
                $r['last_visit'] ?? 'Never', $r['days_since_visit'] ?? '',
            ];
        }
        export_csv(
            "fittrack_attendance_percentage_{$stamp}.csv",
            ['Member Code','Name','Gender','Phone','Email','Plan','Trainer',
             'Join Date','Expiry Date','Status','Attendance %','Band',
             'Last Visit','Days Since Visit'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    // Dropout risk list
    // -----------------------------------------------------------
    case 'dropout_risk':
        $stmt = $pdo->prepare(
            "SELECT v.member_code, v.member_name, v.phone, v.plan_name,
                    v.trainer_name, v.last_visit, v.days_since_visit, v.attendance_pct,
                    CASE
                        WHEN v.days_since_visit IS NULL OR v.days_since_visit >= 21 THEN 'Likely Dropout'
                        WHEN v.days_since_visit >= 10                               THEN 'At Risk'
                    END AS risk_label
             FROM v_member_summary v
             WHERE v.status = 'Active'
               AND (v.days_since_visit IS NULL OR v.days_since_visit >= 10)
             ORDER BY v.days_since_visit IS NULL DESC, v.days_since_visit DESC"
        );
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['member_code'], $r['member_name'], $r['phone'], $r['plan_name'],
                $r['trainer_name'] ?? 'Unassigned', $r['last_visit'] ?? 'Never',
                $r['days_since_visit'] ?? '', number_format((float)$r['attendance_pct'], 2, '.', ''),
                $r['risk_label'],
            ];
        }
        export_csv(
            "fittrack_dropout_risk_{$stamp}.csv",
            ['Member Code','Name','Phone','Plan','Trainer','Last Visit',
             'Days Absent','Attendance %','Risk Flag'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    // Renewal alerts: expiring within 7 days, then already expired
    // -----------------------------------------------------------
    case 'renewals':
        $stmt = $pdo->prepare(
            "SELECT m.member_code, m.member_name, m.phone, p.plan_name, m.expiry_date,
                    DATEDIFF(m.expiry_date, CURDATE()) AS days_left,
                    'Expiring Soon' AS alert_type
             FROM member m JOIN plan p ON p.plan_id = m.plan_id
             WHERE m.status = 'Active'
               AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             UNION ALL
             SELECT m.member_code, m.member_name, m.phone, p.plan_name, m.expiry_date,
                    -DATEDIFF(CURDATE(), m.expiry_date) AS days_left,
                    'Already Expired' AS alert_type
             FROM member m JOIN plan p ON p.plan_id = m.plan_id
             WHERE m.status = 'Expired'
             ORDER BY days_left"
        );
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['alert_type'], $r['member_code'], $r['member_name'], $r['phone'],
                $r['plan_name'], $r['expiry_date'], (int) $r['days_left'],
            ];
        }
        export_csv(
            "fittrack_renewal_alerts_{$stamp}.csv",
            ['Alert Type','Member Code','Name','Phone','Plan','Expiry Date','Days Left (negative = overdue)'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    // Raw attendance rows for a date range (optionally one member)
    // -----------------------------------------------------------
    case 'attendance':
        $where  = ['a.att_date BETWEEN :from AND :to'];
        $params = [':from' => $from_date, ':to' => $to_date];
        if ($member_f > 0) { $where[] = 'a.member_id = :mid'; $params[':mid'] = $member_f; }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT a.att_date, m.member_code, m.member_name, p.plan_name,
                    t.trainer_name, a.check_in, a.check_out, a.duration_min
             FROM attendance a
             JOIN member m ON m.member_id = a.member_id
             JOIN plan   p ON p.plan_id   = m.plan_id
             LEFT JOIN trainer t ON t.trainer_id = m.trainer_id
             $where_sql
             ORDER BY a.att_date DESC, a.check_in DESC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['att_date'], $r['member_code'], $r['member_name'], $r['plan_name'],
                $r['trainer_name'] ?? 'Unassigned', $r['check_in'],
                $r['check_out'] ?? '', $r['duration_min'] ?? '',
            ];
        }
        export_csv(
            "fittrack_attendance_{$from_date}_to_{$to_date}.csv",
            ['Date','Member Code','Name','Plan','Trainer','Check In','Check Out','Duration (min)'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    // Payment ledger for a date range
    // -----------------------------------------------------------
    case 'revenue':
        $where  = ['pay.paid_date BETWEEN :from AND :to'];
        $params = [':from' => $from_date, ':to' => $to_date];
        if (in_array($mode_f, ['Cash','UPI','Card'], true)) {
            $where[] = 'pay.mode = :mode';
            $params[':mode'] = $mode_f;
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT pay.paid_date, m.member_code, m.member_name, p.plan_name,
                    pay.amount, pay.mode, pay.next_due_date
             FROM payment pay
             JOIN member m ON m.member_id = pay.member_id
             JOIN plan   p ON p.plan_id   = pay.plan_id
             $where_sql
             ORDER BY pay.paid_date DESC, pay.payment_id DESC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['paid_date'], $r['member_code'], $r['member_name'], $r['plan_name'],
                number_format((float)$r['amount'], 2, '.', ''), $r['mode'], $r['next_due_date'],
            ];
        }
        export_csv(
            "fittrack_revenue_{$from_date}_to_{$to_date}.csv",
            ['Paid Date','Member Code','Name','Plan','Amount','Mode','Next Due Date'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    // Plan-wise members and revenue
    // -----------------------------------------------------------
    case 'plans':
        $stmt = $pdo->query(
            "SELECT p.plan_name, p.duration_months, p.fee, p.sessions_per_week,
                    (SELECT COUNT(*) FROM member m WHERE m.plan_id = p.plan_id) AS member_count,
                    (SELECT COUNT(*) FROM member m WHERE m.plan_id = p.plan_id AND m.status='Active') AS active_count,
                    (SELECT COALESCE(SUM(y.amount),0) FROM payment y WHERE y.plan_id = p.plan_id) AS revenue
             FROM plan p
             ORDER BY p.duration_months, p.fee"
        );
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['plan_name'], $r['duration_months'], number_format((float)$r['fee'], 2, '.', ''),
                $r['sessions_per_week'], $r['member_count'], $r['active_count'],
                number_format((float)$r['revenue'], 2, '.', ''),
            ];
        }
        export_csv(
            "fittrack_plans_{$stamp}.csv",
            ['Plan','Duration (months)','Fee','Sessions/Week','Total Members','Active Members','Total Revenue'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    // Trainer load
    // -----------------------------------------------------------
    case 'trainers':
        $stmt = $pdo->query(
            "SELECT t.trainer_name, t.specialization, t.shift, t.phone,
                    COUNT(v.member_id)              AS member_count,
                    SUM(v.status = 'Active')        AS active_count,
                    ROUND(AVG(v.attendance_pct), 2) AS avg_attendance_pct
             FROM trainer t
             LEFT JOIN v_member_summary v ON v.trainer_id = t.trainer_id
             GROUP BY t.trainer_id, t.trainer_name, t.specialization, t.shift, t.phone
             ORDER BY member_count DESC, t.trainer_name"
        );
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['trainer_name'], $r['specialization'], $r['shift'], $r['phone'] ?? '',
                $r['member_count'], (int) $r['active_count'],
                $r['avg_attendance_pct'] !== null ? $r['avg_attendance_pct'] : '',
            ];
        }
        export_csv(
            "fittrack_trainers_{$stamp}.csv",
            ['Trainer','Specialization','Shift','Phone','Total Members','Active Members','Avg Attendance % of Members'],
            $rows
        );
        break;

    // -----------------------------------------------------------
    default:
        flash('error', "Unknown report type \"{$report}\".");
        header('Location: ' . base_url('reports/analyzer.php'));
        exit;
}
