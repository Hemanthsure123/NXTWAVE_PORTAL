<?php
// The login page.
//
// Almost no PHP here. The form is never submitted the normal way:
// login.js sends it to api/login.php and reacts to the JSON that comes
// back, so the page never reloads.

require_once __DIR__ . '/../includes/bootstrap.php';

start_session();

if (is_logged_in()) {
    redirect(dashboard_for_role($_SESSION['role_name']));
}

$pageTitle = 'Login';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h1>Login</h1>
    <p class="subtitle">Use the email address you registered with.</p>

    <div id="login-message"></div>

    <form id="login-form" novalidate>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" autocomplete="username">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password">

        <button type="submit" id="login-button">Log in</button>
    </form>

    <p class="form-links">
        <a href="reset-password.php">Forgot your password?</a>
    </p>
</div>

<p class="footnote">
    New here? <a href="register.php">Create an account</a>
</p>

<script src="assets/js/login.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
