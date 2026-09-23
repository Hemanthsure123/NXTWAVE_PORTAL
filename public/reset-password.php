<?php
// Forgot password, all three steps on one page.
//
//   1. enter email         -> api/request-otp.php
//   2. enter the 6 digits  -> api/verify-otp.php
//   3. new password        -> api/reset-password.php
//
// reset.js hides one step and shows the next. The page never reloads.

require_once __DIR__ . '/../includes/bootstrap.php';

start_session();

$pageTitle = 'Reset password';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h1>Reset your password</h1>

    <div id="reset-message"></div>

    <form id="step-email" novalidate>
        <p class="subtitle">We will email you a 6 digit code.</p>

        <label for="email">Registered email</label>
        <input type="email" id="email" name="email">

        <button type="submit">Send code</button>
    </form>

    <form id="step-otp" novalidate hidden>
        <p class="subtitle">
            Enter the 6 digit code we sent to <strong id="otp-target"></strong>.
            It is valid for 15 minutes.
        </p>

        <label for="otp">Verification code</label>
        <input type="text" id="otp" name="otp" inputmode="numeric" maxlength="6"
               autocomplete="one-time-code">

        <button type="submit">Verify code</button>
    </form>

    <form id="step-password" novalidate hidden>
        <p class="subtitle">Code accepted. Pick a new password.</p>

        <label for="password">New password</label>
        <input type="password" id="password" name="password" autocomplete="new-password">
        <small class="hint">At least 8 characters.</small>

        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">

        <button type="submit">Update password</button>
    </form>

</div>

<p class="footnote"><a href="login.php">Back to login</a></p>

<script src="assets/js/reset.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
