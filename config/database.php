<?php
// The database connection. bootstrap.php loads this, so every page that
// includes the bootstrap can use $pdo.

$host = '127.0.0.1';
$name = 'nxtwave_portal';
$user = 'root';
$pass = '';              // XAMPP's MySQL has no password by default

$options = [
    // Throw when a query fails, instead of quietly returning false.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

    // Rows come back as $row['email'], not $row[0].
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    // Let MySQL do the prepared statement for real. This is the setting
    // that makes placeholders genuinely safe.
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    // The real reason goes to the log. The visitor gets a plain sentence:
    // the exception message contains the database name and the username.
    error_log('Database connection failed: ' . $e->getMessage());

    abort(503, 'The portal is temporarily unavailable. Please try again in a minute.');
}
