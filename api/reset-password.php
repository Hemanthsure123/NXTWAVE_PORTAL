<?php
// POST api/reset-password.php   body: {"password": "...", "confirm_password": "..."}
//
// Step 3. Only a browser that just passed verify-otp.php can get here,
// because the user id comes out of the session.

require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    json_response(['status' => 'error', 'message' => 'Use POST'], 405);
}

start_session();

$userId   = $_SESSION['reset_user_id'] ?? null;
$resetId  = $_SESSION['reset_row_id'] ?? null;
$verified = $_SESSION['reset_verified'] ?? 0;

// Permission to change a password should not sit around all afternoon on a
// shared computer. Ten minutes from verifying the code.
if (!$userId || (time() - $verified) > 600) {
    json_response(['status' => 'error', 'message' => 'This reset session has expired. Please start again.'], 403);
}

$input    = json_input();
$password = $input['password'] ?? '';
$confirm  = $input['confirm_password'] ?? '';

if (strlen($password) < 8) {
    json_response(['status' => 'error', 'message' => 'Password must be at least 8 characters']);
}

if ($password !== $confirm) {
    json_response(['status' => 'error', 'message' => 'The two passwords do not match']);
}

// Three writes that must all happen or none of them. The worst outcome
// would be changing the password but leaving the code usable.
$pdo->beginTransaction();

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

// One-time use. Step 2 only looks for codes WHERE used_at IS NULL, so the
// same six digits cannot be replayed by anyone who saw the email.
$pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
    ->execute([$resetId]);

// Tidy up this user's spent and stale codes.
$pdo->prepare(
    'DELETE FROM password_resets
     WHERE user_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())'
)->execute([$userId]);

$pdo->commit();

// The password changed, so every session for this account is suspect,
// including this one.
logout_user();

json_response([
    'status'  => 'success',
    'message' => 'Password updated. You can log in with your new password.',
]);
