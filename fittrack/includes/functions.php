<?php
/**
 * File    : includes/functions.php
 * Purpose : Shared helper functions used across all modules.
 *           Validation, member-code generation, attendance percentage,
 *           expiry computation, flash messages, CSV export.
 * Module  : All Modules
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/../config/db.php';

// ----------------------------------------------------------------
// VALIDATION HELPERS
// ----------------------------------------------------------------

/**
 * Validate that a string is a 10-digit phone number.
 */
function validate_phone(string $phone): bool {
    return (bool) preg_match('/^\d{10}$/', $phone);
}

/**
 * Validate email format.
 */
function validate_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate age from DOB: must be between 12 and 80 years.
 * Returns the age in years, or false if out of range.
 */
function validate_age(string $dob): int|false {
    $birth = new DateTime($dob);
    $today = new DateTime();
    $age   = (int) $today->diff($birth)->y;
    if ($age < 12 || $age > 80) return false;
    return $age;
}

/**
 * Check that a phone number is not already taken by another member.
 * Excludes the given member_id (for edit forms).
 */
function phone_exists(string $phone, int $exclude_member_id = 0): bool {
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT 1 FROM member WHERE phone = :phone AND member_id != :mid LIMIT 1'
    );
    $stmt->execute([':phone' => $phone, ':mid' => $exclude_member_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Check that an email is not already taken by another member.
 */
function email_exists(string $email, int $exclude_member_id = 0): bool {
    if ($email === '') return false;
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT 1 FROM member WHERE email = :email AND member_id != :mid LIMIT 1'
    );
    $stmt->execute([':email' => $email, ':mid' => $exclude_member_id]);
    return (bool) $stmt->fetchColumn();
}

// ----------------------------------------------------------------
// MEMBER CODE GENERATOR
// ----------------------------------------------------------------

/**
 * Auto-generate the next member code in format FT-YYYY-NNN.
 * Reads the current year and the highest sequential number in the DB.
 */
function generate_member_code(): string {
    $pdo  = get_db();
    $year = date('Y');

    // Find the highest existing sequence number for this year
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(member_code, '-', -1) AS UNSIGNED))
         FROM member
         WHERE member_code LIKE :prefix"
    );
    $stmt->execute([':prefix' => "FT-{$year}-%"]);
    $max = (int) $stmt->fetchColumn();

    // Zero-pad to 3 digits
    return sprintf('FT-%s-%03d', $year, $max + 1);
}

// ----------------------------------------------------------------
// EXPIRY DATE CALCULATOR
// ----------------------------------------------------------------

/**
 * Compute expiry date = join_date + plan.duration_months.
 * Returns a date string Y-m-d.
 */
function compute_expiry(string $join_date, int $duration_months): string {
    $dt = new DateTime($join_date);
    $dt->modify("+{$duration_months} months");
    return $dt->format('Y-m-d');
}

/**
 * Renew: new expiry = GREATEST(current_expiry, today) + duration_months.
 */
function compute_renewal_expiry(string $current_expiry, int $duration_months): string {
    $today      = new DateTime();
    $expiry_dt  = new DateTime($current_expiry);
    $base       = max($today, $expiry_dt);
    $base->modify("+{$duration_months} months");
    return $base->format('Y-m-d');
}

// ----------------------------------------------------------------
// ATTENDANCE PERCENTAGE HELPER
// ----------------------------------------------------------------

/**
 * Compute attendance percentage for one member.
 * present_days / min(elapsed_days, plan_duration_days) * 100
 * Guarded against division by zero.
 */
function attendance_pct(int $present_days, string $join_date, int $duration_months): float {
    $elapsed = (int) (new DateTime())->diff(new DateTime($join_date))->days + 1;
    $plan_days = $duration_months * 30;
    $divisor = min($elapsed, $plan_days);
    if ($divisor <= 0) return 0.0;
    return round(($present_days / $divisor) * 100, 2);
}

/**
 * Return the attendance band label and Bootstrap colour class for a given %.
 */
function attendance_band(float $pct): array {
    if ($pct >= 75) return ['label' => 'Excellent', 'class' => 'success'];
    if ($pct >= 50) return ['label' => 'Good',      'class' => 'info'];
    if ($pct >= 25) return ['label' => 'Poor',      'class' => 'warning'];
    return             ['label' => 'Critical',   'class' => 'danger'];
}

// ----------------------------------------------------------------
// DROPOUT RISK CLASSIFIER
// ----------------------------------------------------------------

/**
 * Given days_since_last_visit and member status, return risk label.
 */
function dropout_risk(?int $days_since, string $status): string {
    if ($status !== 'Active') return '';
    if ($days_since === null || $days_since >= 21) return 'Likely Dropout';
    if ($days_since >= 10)                          return 'At Risk';
    return '';
}

// ----------------------------------------------------------------
// STREAK CALCULATOR (Module 4.3)
// ----------------------------------------------------------------

/**
 * Compute the current streak and longest streak of consecutive attended
 * days for ONE member, given their attendance dates.
 *
 * This walks a single member's own (small, bounded) list of dates with a
 * plain PHP loop rather than a SQL window function — for one member's
 * report card this is simpler to read and explain in a viva than a
 * gaps-and-islands window-function query, and it is NOT the "PHP loop
 * over the full members table" pattern the analyzer avoids elsewhere.
 *
 * @param string[] $dates Attendance dates for one member, any order,
 *                        format 'Y-m-d'. Duplicates are ignored.
 * @return array{current:int, longest:int}
 */
function compute_streaks(array $dates): array {
    if (empty($dates)) {
        return ['current' => 0, 'longest' => 0];
    }

    // Sort ascending and drop duplicates
    $dates = array_values(array_unique($dates));
    sort($dates);

    $longest    = 1;
    $run        = 1;
    $today      = new DateTime('today');

    for ($i = 1, $n = count($dates); $i < $n; $i++) {
        $prev = new DateTime($dates[$i - 1]);
        $curr = new DateTime($dates[$i]);
        $gap  = (int) $prev->diff($curr)->days;

        if ($gap === 1) {
            $run++;
        } else {
            $longest = max($longest, $run);
            $run = 1;
        }
    }
    $longest = max($longest, $run);

    // Current streak: walk backward from the last visit. It only counts as
    // "current" if the last visit was today or yesterday — otherwise the
    // streak has already been broken by absence.
    $last_visit = new DateTime(end($dates));
    $gap_to_today = (int) $last_visit->diff($today)->days;

    if ($gap_to_today > 1) {
        $current = 0;
    } else {
        $current = 1;
        for ($i = count($dates) - 1; $i > 0; $i--) {
            $prev = new DateTime($dates[$i - 1]);
            $curr = new DateTime($dates[$i]);
            if ((int) $prev->diff($curr)->days === 1) {
                $current++;
            } else {
                break;
            }
        }
    }

    return ['current' => $current, 'longest' => $longest];
}

// ----------------------------------------------------------------
// FLASH MESSAGE HELPERS
// ----------------------------------------------------------------

/**
 * Store a one-time flash message in the session.
 */
function flash(string $key, string $message): void {
    $_SESSION['flash'][$key] = $message;
}

/**
 * Retrieve and clear a flash message. Returns '' if not set.
 */
function get_flash(string $key): string {
    $msg = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $msg;
}

/**
 * Render Bootstrap alert HTML for a flash key, or '' if empty.
 */
function render_flash(string $key): string {
    $msg = get_flash($key);
    if ($msg === '') return '';

    $type = match(true) {
        str_starts_with($key, 'success') => 'success',
        str_starts_with($key, 'error')   => 'danger',
        str_starts_with($key, 'warning') => 'warning',
        default                           => 'info',
    };

    return sprintf(
        '<div class="alert alert-%s alert-dismissible fade show" role="alert">'
        . '<i class="bi bi-check-circle-fill me-2"></i>%s'
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
        . '</div>',
        $type,
        htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
    );
}

// ----------------------------------------------------------------
// CSV EXPORT HELPER
// ----------------------------------------------------------------

/**
 * Send CSV headers and output an array of rows as a downloadable CSV.
 *
 * @param string   $filename  Download filename (no path)
 * @param array    $headers   Column header labels
 * @param array    $rows      2-D array of data rows
 */
function export_csv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

// ----------------------------------------------------------------
// MISC HELPERS
// ----------------------------------------------------------------

/**
 * Safely echo a value with htmlspecialchars.
 */
function h(mixed $val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format a date string to d M Y (e.g., 15 Jan 2026), or '—' if empty.
 */
function fmt_date(?string $date): string {
    if (empty($date) || $date === '0000-00-00') return '—';
    return date('d M Y', strtotime($date));
}

/**
 * Format a TIME value (HH:MM:SS) to HH:MM.
 */
function fmt_time(?string $time): string {
    if (empty($time)) return '—';
    return substr($time, 0, 5);
}

/**
 * Returns a coloured badge span for member status.
 */
function status_badge(string $status): string {
    $map = [
        'Active'    => 'success',
        'Expired'   => 'warning text-dark',
        'Cancelled' => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return "<span class=\"badge bg-{$cls}\">" . h($status) . '</span>';
}
