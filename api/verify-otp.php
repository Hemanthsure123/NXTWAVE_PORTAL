<?php
// POST api/verify-otp.php    body: {"email": "...", "otp": "123456"}
//
// Step 2. Four things have to be true: the format is right, the code has
// not expired, it has not been used, and it matches.

require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    json_response(['status' => 'error', 'message' => 'Use POST'], 405);
}

$input = json_input();
$email = strtolower(trim($input['email'] ?? ''));
$otp   = trim($input['otp'] ?? '');

if (!preg_match('/^[0-9]{6}$/', $otp)) {
    json_response(['status' => 'invalid', 'message' => 'Enter the 6 digit code']);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    json_response(['status' => 'invalid', 'message' => 'Invalid or expired code']);
}

// The newest unused code for this user. ORDER BY id DESC LIMIT 1 is SQL
// for "the last one".
$stmt = $pdo->prepare(
    'SELECT id, otp_hash, attempts, expires_at
     FROM   password_resets
     WHERE  user_id = ? AND used_at IS NULL
     ORDER  BY id DESC
     LIMIT  1'
);
$stmt->execute([$user['id']]);
$reset = $stmt->fetch();

if (!$reset) {
    json_response(['status' => 'invalid', 'message' => 'Invalid or expired code']);
}

// strtotime turns '2026-09-23 18:40:00' into seconds, which compares
// nicely with time().
if (time() > strtotime($reset['expires_at'])) {
    json_response(['status' => 'expired', 'message' => 'That code has expired. Please request a new one.']);
}

// Guessing six digits takes a million tries. Five is as far as anyone gets.
if ((int) $reset['attempts'] >= 5) {
    json_response(['status' => 'invalid', 'message' => 'Too many wrong attempts. Request a new code.']);
}

// Counted in SQL, not in PHP. Two requests at once would both read the same
// number and both write back the same number.
$pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')
    ->execute([$reset['id']]);

if (!password_verify($otp, $reset['otp_hash'])) {
    json_response(['status' => 'invalid', 'message' => 'Invalid or expired code']);
}

// The code is right, but we do not mark it used yet - the password has not
// changed. Instead we remember in the session that this browser passed the
// check. Step 3 reads the user id from there and nowhere else, so nobody
// can POST someone else's id and take over their account.
start_session();
session_regenerate_id(true);

$_SESSION['reset_user_id']  = (int) $user['id'];
$_SESSION['reset_row_id']   = (int) $reset['id'];
$_SESSION['reset_verified'] = time();

json_response(['status' => 'verified', 'message' => 'Code verified. Choose a new password.']);
