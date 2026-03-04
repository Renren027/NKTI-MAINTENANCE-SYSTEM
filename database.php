<?php
// ============================================================
// config/database.php  — NKTI BIOMED MedTracker
// ============================================================
// Edit these values to match your MySQL installation.

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'nkti_biomed');
define('DB_USER', 'nkti_user');        // create this MySQL user (see README)
define('DB_PASS', 'StrongPass!2025');  // change this!
define('DB_CHARSET', 'utf8mb4');

// Session settings
define('SESSION_LIFETIME', 28800);     // 8 hours in seconds
define('SESSION_NAME', 'NKTI_SESS');

// App settings
define('APP_NAME', 'NKTI BIOMED MedTracker');
define('APP_VERSION', '2.0.0');
define('APP_ENV', 'production');       // 'development' shows extra errors

// ─── PDO connection (singleton) ──────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed. Check config/database.php']));
        }
    }
    return $pdo;
}
