<?php
// Creates a NxtWave employee (staff) account.
//
// Run it from a terminal, in the project folder:
//     php bin/create-employee.php
//
// Staff accounts have no signup form on purpose: nobody should be able to
// make themselves an admin. This script asks for the details instead of
// taking them as arguments, so the password never lands in your shell
// history.

// PHP_SAPI tells us how this file was started. 'cli' means a terminal.
// Anything else means somebody found the URL, and they get nothing.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Read one line from the terminal.
function ask(string $question): string
{
    echo $question;

    return trim(fgets(STDIN));
}

// Print the problem and stop.
function fail(string $message): never
{
    exit($message . "\n");
}

echo "Create a NxtWave employee account\n";
echo "---------------------------------\n";

$name = ask('Full name : ');

if ($name === '') {
    fail('Name cannot be empty.');
}

$email = strtolower(ask('Email     : '));

if (!is_valid_email($email)) {
    fail('That is not a valid email address.');
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);

if ($stmt->fetch()) {
    fail("An account with {$email} already exists.");
}

$phone = ask('Phone     : ');

if (!is_valid_phone($phone)) {
    fail('Phone must be a 10 digit mobile number.');
}

$city = ask('City      : ');

if ($city === '') {
    fail('City cannot be empty.');
}

// Longer than the 8 we ask the public for. Staff accounts can approve
// companies and read everyone's documents.
$password = ask('Password  : ');

if (strlen($password) < 10) {
    fail('Staff passwords must be at least 10 characters.');
}

if ($password !== ask('Confirm   : ')) {
    fail('The passwords do not match.');
}

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'employee'")->fetchColumn();

if ($roleId === 0) {
    fail('Run sql/schema.sql first - the roles table is empty.');
}

$stmt = $pdo->prepare(
    'INSERT INTO users (role_id, full_name, email, phone, city, password_hash, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$stmt->execute([
    $roleId,
    $name,
    $email,
    $phone,
    $city,
    password_hash($password, PASSWORD_DEFAULT),
    'active',
]);

echo "\nDone. {$email} can now sign in at /public/login.php\n";
