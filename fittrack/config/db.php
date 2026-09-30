<?php
/**
 * File    : config/db.php
 * Purpose : PDO database connection — single place to configure DB credentials
 * Module  : All Modules (shared config)
 * Author  : Pavithran
 * College : Kamaraj College of Engineering and Technology
 * Course  : CS2307 - Internet Programming Laboratory
 */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);       // XAMPP's default MySQL port
define('DB_NAME', 'fittrack_db');
define('DB_USER', 'root');
define('DB_PASS', '');         // Change if your XAMPP MySQL has a password
define('DB_CHARSET', 'utf8mb4');

/*
 * Application timezone. PHP defaults to UTC when php.ini has no
 * date.timezone set, which would make check-in times (and date('Y-m-d')
 * near midnight) disagree with MySQL's CURDATE()/NOW(). Pinning it to
 * IST keeps PHP and MySQL on the same day and the same clock.
 */
date_default_timezone_set('Asia/Kolkata');

/**
 * Returns a singleton PDO connection.
 * Error mode: EXCEPTION — any SQL error throws a catchable exception.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Show a friendly error and stop — never expose DB details to the browser
            die('<div style="font-family:sans-serif;color:#c00;padding:2rem;">'
                . '<h2>Database Connection Failed</h2>'
                . '<p>Could not connect to <strong>fittrack_db</strong>. '
                . 'Please ensure XAMPP MySQL is running and you have imported '
                . '<code>sql/fittrack.sql</code>.</p>'
                . '<p><em>Technical detail (dev only):</em> '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                . '</p></div>');
        }
    }
    return $pdo;
}
