<?php
// Sessions, page guards and the CSRF token.
//
// PHP forgets everything between requests, so after a successful login we
// stash the user in a session. The browser only holds a random id; the
// actual data stays on the server.

// session_start() sends a cookie header, so it has to run before any HTML.
// The status check lets several files call this without complaining.
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,   // JavaScript cannot read the cookie
            'samesite' => 'Lax',  // not sent on cross-site POSTs

            // Send it over HTTPS only. On plain localhost that would stop
            // the cookie working at all, so we follow the connection.
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

// Called once, right after the password checks out.
function login_user(array $user, string $roleName): void
{
    start_session();

    // If an attacker managed to plant a session id before login, this
    // throws it away and issues a fresh one.
    session_regenerate_id(true);

    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['full_name']  = $user['full_name'];
    $_SESSION['role_name']  = $roleName;
    $_SESSION['auth_token'] = bin2hex(random_bytes(32));
}

function is_logged_in(): bool
{
    start_session();

    return isset($_SESSION['user_id']);
}

// Put this at the top of any page that needs a login.
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

// And this when only one role may open the page.
// Hiding the link in the menu is not protection: anyone can type the URL.
function require_role(string $roleName): void
{
    require_login();

    if (($_SESSION['role_name'] ?? '') !== $roleName) {
        abort(403, 'This page is not available for your account.');
    }
}

function current_role(): string
{
    return $_SESSION['role_name'] ?? '';
}

// The roles table stores 'corporate_hr'. People should not have to read that.
function role_label(string $roleName): string
{
    return match ($roleName) {
        'learner'      => 'Learner',
        'corporate_hr' => 'Corporate HR',
        'employee'     => 'NxtWave Team',
        default        => '',
    };
}

// Where each role lands after logging in.
// match is PHP 8's tidier switch: it compares with === and returns a value.
function dashboard_for_role(string $roleName): string
{
    return match ($roleName) {
        'learner'      => 'learner-dashboard.php',
        'corporate_hr' => 'corporate-dashboard.php',
        'employee'     => 'admin-dashboard.php',
        default        => 'login.php',
    };
}

// All three steps matter. Clearing $_SESSION without destroying the file
// leaves a usable session sitting on the server.
function logout_user(): void
{
    start_session();

    $_SESSION = [];

    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);

    session_destroy();
}

// CSRF token.
//
// You are logged in here. You open evil.com, which quietly submits a form
// to our approve endpoint. Your browser attaches your session cookie, so to
// us it looks like you clicked it. evil.com cannot read this token, so its
// forged request has no way to include it.
function csrf_token(): string
{
    start_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// hash_equals compares in constant time, so nobody can work out the token
// one character at a time by measuring how long we took to say no.
function csrf_is_valid(?string $token): bool
{
    start_session();

    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}
