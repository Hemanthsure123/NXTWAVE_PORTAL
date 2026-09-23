<?php
// POST api/login.php    body: {"email": "...", "password": "..."}
//
// Always replies with one of three shapes:
//   {"status": "success",                "redirect": "learner-dashboard.php"}
//   {"status": "invalid_credentials",    "message": "..."}
//   {"status": "account_pending_review", "message": "..."}

require_once __DIR__ . '/../includes/bootstrap.php';

// A GET would put the password in the URL, which means browser history and
// server logs. 405 is the status for "wrong method".
if (!is_post()) {
    json_response(['status' => 'error', 'message' => 'Use POST'], 405);
}

$input    = json_input();
$email    = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '';

if ($email === '' || $password === '') {
    json_response(['status' => 'invalid_credentials', 'message' => 'Please enter your email and password']);
}

// Hashing is slow by design, but not slow enough to stop a script trying a
// few thousand common passwords. Five failures in fifteen minutes and this
// account stops answering for a while.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM login_attempts
     WHERE email = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
);
$stmt->execute([$email]);

if ((int) $stmt->fetchColumn() >= 5) {
    json_response([
        'status'  => 'invalid_credentials',
        'message' => 'Too many failed attempts. Please try again in 15 minutes.',
    ], 429);
}

// We need the role name, which lives in the other table, so we join.
$stmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.password_hash, u.status, r.name AS role_name
     FROM   users u
     JOIN   roles r ON r.id = u.role_id
     WHERE  u.email = ?'
);
$stmt->execute([$email]);

$user = $stmt->fetch();

// Same message for "no such user" and "wrong password" on purpose.
// If we said "no account with that email", anyone could feed us a list of
// addresses and find out which ones bank with us.
if (!$user || !password_verify($password, $user['password_hash'])) {
    $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
        ->execute([$email, $ip]);

    // Old rows are no use to anyone. Clearing them here means we never need
    // a scheduled job just for this table.
    $pdo->query('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');

    json_response(['status' => 'invalid_credentials', 'message' => 'Invalid email or password']);
}

// Password was right, but corporate accounts wait for approval first.
// We check this after the password, otherwise we would be telling
// strangers which emails have pending accounts.
if ($user['status'] !== 'active') {
    json_response([
        'status'  => 'account_pending_review',
        'message' => 'Your account is waiting for NxtWave approval',
    ]);
}

// A correct password wipes the slate, so a few typos earlier today cannot
// lock someone out once they have remembered it.
$pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);

login_user($user, $user['role_name']);

// The server decides where they go. JavaScript only follows.
json_response([
    'status'   => 'success',
    'message'  => 'Login successful',
    'redirect' => dashboard_for_role($user['role_name']),
]);
