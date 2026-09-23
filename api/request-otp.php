<?php
// POST api/request-otp.php    body: {"email": "..."}
//
// Step 1 of the reset: make a 6 digit code, store it, email it.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!is_post()) {
    json_response(['status' => 'error', 'message' => 'Use POST'], 405);
}

$input = json_input();
$email = strtolower(trim($input['email'] ?? ''));

if (!is_valid_email($email)) {
    json_response(['status' => 'error', 'message' => 'Please enter a valid email address']);
}

$stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

// Notice we say the same thing whether or not the account exists.
// Otherwise this page becomes a free tool for checking who registered here.
if (!$user) {
    json_response([
        'status'  => 'sent',
        'message' => 'If that email is registered, a 6 digit code is on its way.',
    ]);
}

// Three codes in fifteen minutes is plenty for a real person. Without this,
// a script could use our server to flood someone's inbox.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM password_resets
     WHERE user_id = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
);
$stmt->execute([$user['id']]);

if ((int) $stmt->fetchColumn() >= 3) {
    json_response([
        'status'  => 'error',
        'message' => 'Too many requests. Please wait a few minutes and try again.',
    ], 429);
}

// random_int is the unpredictable one. rand() and mt_rand() walk a formula
// forward from a seed, so given a few outputs you can work out the rest.
$otp = (string) random_int(100000, 999999);

// For the next fifteen minutes this code IS the password, so it gets
// hashed exactly like one.
$otpHash = password_hash($otp, PASSWORD_DEFAULT);

// Asking for a new code kills every older one.
$pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);

// MySQL does the clock maths, so PHP's timezone cannot disagree with it.
$pdo->prepare(
    'INSERT INTO password_resets (user_id, otp_hash, expires_at)
     VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)'
)->execute([$user['id'], $otpHash]);

send_otp_email($email, $user['full_name'], $otp);

json_response([
    'status'  => 'sent',
    'message' => 'If that email is registered, a 6 digit code is on its way.',
]);
