# Lesson 11 — OTP password reset

**Goal:** the full three-step flow — request a 6 digit code, verify it
without a page reload, set a new password — with a 15 minute expiry,
one-time use, and cleanup.

Everything here is a recombination of things you have already built. The new
material is randomness, time, and email.

---

## 1. The shape of the feature

```
Step 1   user types their email        → api/request-otp.php
         PHP makes a 6 digit code, stores its hash, emails the code

Step 2   user types the code           → api/verify-otp.php
         PHP checks: matches? expired? already used? too many tries?

Step 3   user types a new password     → api/reset-password.php
         PHP updates the hash, marks the code used, purges old rows
```

All three steps live on one page, `public/reset-password.php`, as three
`<form>` elements. JavaScript hides one and shows the next. The page never
reloads.

Ask the class first: **why an OTP at all?** Why not email a "click here to
reset" link? Both are fine; both prove the same thing — *you control that
inbox*. A 6 digit code works better on a phone, where switching apps loses
the browser tab, and it is the pattern every Indian app already uses.

---

## 2. Generating the code

```php
$otp = (string) random_int(100000, 999999);
```

`random_int(min, max)` is inclusive at both ends, so the range is exactly the
six-digit numbers — no leading-zero problem.

### Why not `rand()`

Run this:

```php
mt_srand(42);
echo mt_rand(100000, 999999), ' ';
echo mt_rand(100000, 999999), ' ';
mt_srand(42);
echo mt_rand(100000, 999999);      // the first number, again
```

`rand()` and `mt_rand()` are *pseudo*-random: a formula walking forward from
a seed. Fast, fine for shuffling a quiz, and fatal here — given enough
outputs, the sequence is reconstructible, and an attacker who can request
their own OTPs gets as many outputs as they like.

`random_int()` draws from the operating system's cryptographic source. It is
slower and unpredictable, which is the trade we want.

> **The rule:** if the number guards something, it comes from
> `random_int()` or `random_bytes()`. Never `rand()`.
> Python's equivalent is the `secrets` module rather than `random`.
> Node's is `crypto.randomInt()` rather than `Math.random()`.

We already used the same family in lesson 06 for filenames and in lesson 10
for `auth_token`.

### Why 6 digits is enough

A million possibilities is not a lot. It is enough here because of three
limits working together:

- the code dies after **15 minutes**
- it is **one-time use**
- we allow **5 wrong attempts**, then it is dead

Five guesses out of a million, inside a 15 minute window. Without those
limits, six digits would be trivially brute-forced — the length is not what
makes it safe, the constraints are.

---

## 3. The table

```sql
CREATE TABLE password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    otp_hash   VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    attempts   INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

Column by column:

- **`otp_hash`** — hashed, not stored plainly. Same reasoning as lesson 05:
  for fifteen minutes that code *is* the password. Anyone who can read the
  table — a backup, a SQL injection elsewhere, a curious colleague — could
  otherwise take over accounts.
- **`expires_at`** is `DATETIME`, not `TIMESTAMP`. We are choosing this
  moment ourselves. `TIMESTAMP` is for "when did this row appear".
- **`used_at`** is `NULL` until spent. `NULL` here means "not yet", which is
  different from any date, and it makes `WHERE used_at IS NULL` read exactly
  like the sentence you would say out loud.
- **`attempts`** — the counter that makes guessing pointless.

---

## 4. Step 1 — request the code

[api/request-otp.php](../api/request-otp.php), in order:

**a) Don't leak who has an account.**

```php
if (!$user) {
    json_response([
        'status'  => 'sent',
        'message' => 'If that email is registered, a 6 digit code is on its way.',
    ]);
}
```

Same reply whether or not the address exists — the user enumeration lesson
from lesson 09, applied again. Note the message is phrased so it is not a
lie either way.

**b) Rate limit.**

```php
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM password_resets
     WHERE user_id = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
);
$stmt->execute([$user['id']]);

if ((int) $stmt->fetchColumn() >= 3) {
    json_response([...], 429);
}
```

Without this, a script can send a thousand emails to someone's inbox using
your server, and your sending domain gets blacklisted. Three in fifteen
minutes is generous for a human.

`429` is the HTTP status for "too many requests". `COUNT(*)` with
`fetchColumn()` is the natural pairing — one number, one call.

**c) Kill the old codes.**

```php
$pdo->prepare('UPDATE password_resets SET used_at = NOW()
               WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);
```

Ask for a new code and every earlier one dies immediately. Otherwise three
codes are live at once and each is another thing that can be guessed or
intercepted.

**d) Store the hash with an expiry.**

```php
$pdo->prepare(
    'INSERT INTO password_resets (user_id, otp_hash, expires_at)
     VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)'
)->execute([$user['id'], $otpHash]);
```

`NOW() + INTERVAL 15 MINUTE` lets **MySQL** do the arithmetic. If PHP
computed it with `date()`, a mismatch between the PHP timezone and the MySQL
timezone would make codes expire early or late — a bug that only shows up in
production, on a server in another region. One clock, no drift.

**e) Send it.**

```php
send_otp_email($email, $user['full_name'], $otp);
```

---

## 5. Sending the email

PHP has `mail()`. It hands the message to a local mail program, which XAMPP
does not have, so on your laptop it simply fails. Even where it works, mail
sent that way has nothing proving it came from your domain (no SPF, no
DKIM), so it lands in spam.

Real applications use **SMTP** — they log in to a mail server as a client
and hand it the message. That mail server has the reputation and the
authentication records that make inboxes accept it.

```
Your PHP  ──SMTP──►  smtp.provider.com  ──►  recipient's mail server
                      (authenticated,
                       SPF/DKIM signed)
```

**Transactional** email means mail triggered by one user's action — OTP,
receipt, "your account was approved". Different from marketing mail:
different sending domains, different rules, different consequences when it
fails.

### Setting it up with Gmail

Writing the SMTP conversation by hand is a lot of fiddly work, so we use a
library everybody uses:

```bash
composer require phpmailer/phpmailer
```

That creates a `vendor/` folder. One line loads everything inside it:

```php
require_once dirname(__DIR__) . '/vendor/autoload.php';
```

> **Compared to what you know**
> Composer is PHP's `pip` or `npm`. `composer.json` is the list of
> dependencies, `vendor/` is `site-packages` or `node_modules`, and you do
> not commit it — anyone can rebuild it with `composer install`.

Gmail will not accept your normal password from a script. You create an
**app password** instead, at
[myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords),
after turning on 2-step verification. It is a 16 character string that only
works for sending mail and can be revoked on its own.

[config/mail.php](../config/mail.php) holds it:

```php
return [
    'host'      => 'smtp.gmail.com',
    'port'      => 587,
    'username'  => 'you@gmail.com',
    'password'  => 'your-app-password',
    'from_name' => 'NxtWave Portal',
];
```

Port 587 with `ENCRYPTION_STARTTLS` is the normal combination: the
connection starts as plain text and is upgraded to TLS before the password
is sent. (Port 465 with `ENCRYPTION_SMTPS` starts encrypted instead. Both
work; 587 is what most providers document.)

That file is in `.gitignore`. Anyone who reads it can send email as you.

### The sending code

[includes/mailer.php](../includes/mailer.php) is one function. Settings,
addresses, message, send:

```php
$mail = new PHPMailer();

$mail->isSMTP();
$mail->Host       = $config['host'];
$mail->Port       = $config['port'];
$mail->SMTPAuth   = true;
$mail->Username   = $config['username'];
$mail->Password   = $config['password'];
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

$mail->setFrom($config['username'], $config['from_name']);
$mail->addAddress($toEmail, $name);

$mail->isHTML(true);
$mail->Subject = 'Your NxtWave Portal password reset code';
$mail->Body    = otp_email_body($name, $otp);
$mail->AltBody = "Hi {$name}, your password reset code is {$otp}.";

if ($mail->send()) {
    return true;
}

error_log('OTP email failed: ' . $mail->ErrorInfo);

return false;
```

Three things to point at:

**`AltBody`** is the plain text version. A well-formed email carries both,
and some mail clients and spam filters only look at the text one.

**`setFrom` uses the same address we logged in with.** Gmail rewrites the
From header to the authenticated account anyway, so pretending to be someone
else simply does not work.

**The failure path.** `send()` returns `false` when something goes wrong —
wrong app password, no internet, Gmail throttling. The user must never see
an SMTP error, so it goes to the PHP error log (`C:\xampp\php\logs\`) and
the page carries on. Note this is one of the few places where returning a
boolean beats throwing: a failed email should not roll back anything.

**Test it before wiring it into the flow.** Make a throwaway file:

```php
<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

var_dump(send_otp_email('you@gmail.com', 'Test', '123456'));
```

Run it, check your inbox, then delete the file. If it prints `false`, the
reason is waiting in the error log.

---

## 6. Step 2 — verify the code

[api/verify-otp.php](../api/verify-otp.php) checks four things, in this
order:

```php
// format
if (!preg_match('/^[0-9]{6}$/', $otp)) { ... }

// newest unused code for this user
$stmt = $pdo->prepare(
    'SELECT id, otp_hash, attempts, expires_at
     FROM   password_resets
     WHERE  user_id = ? AND used_at IS NULL
     ORDER  BY id DESC
     LIMIT  1'
);
```

`ORDER BY id DESC LIMIT 1` is SQL for "the most recent one".

```php
// expiry
if (time() > strtotime($reset['expires_at'])) {
    json_response(['status' => 'expired', ...]);
}
```

`time()` is the current Unix timestamp — seconds since 1970.
`strtotime('2026-09-23 18:40:00')` converts MySQL's string into the same
unit, so comparing is plain arithmetic. That is the whole of "timestamp
comparison".

```php
// attempts
if ((int) $reset['attempts'] >= 5) { ... }

$pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')
    ->execute([$reset['id']]);

// finally, the code itself
if (!password_verify($otp, $reset['otp_hash'])) { ... }
```

Note the counter is incremented **before** the comparison, and it is done in
SQL (`attempts = attempts + 1`) rather than by reading, adding in PHP and
writing back. Two simultaneous requests would both read `2` and both write
`3`; letting MySQL do the increment keeps the count honest.

### Distinct statuses again

```json
{ "status": "verified", ... }
{ "status": "expired",  ... }
{ "status": "invalid",  ... }
```

`expired` is separate because the front end reacts differently — it sends
the user back to step 1 instead of letting them retype a dead code.

### What happens on success

```php
start_session();
session_regenerate_id(true);

$_SESSION['reset_user_id']  = (int) $user['id'];
$_SESSION['reset_row_id']   = (int) $reset['id'];
$_SESSION['reset_verified'] = time();
```

The code is **not** marked used yet — the password has not changed, and
marking it now would strand anyone whose browser crashed on step 3.

Instead we record in the session that *this browser* passed the check. Step
3 will read the user id from there. If it instead accepted a `user_id` in
the request body, anyone could POST `{"user_id": 1, "password": "..."}` and
own the admin account. Same principle as lesson 10: **identity comes from the
session.**

---

## 7. Step 3 — set the new password

```php
$userId   = $_SESSION['reset_user_id'] ?? null;
$verified = $_SESSION['reset_verified'] ?? 0;

if (!$userId || (time() - $verified) > 600) {
    json_response(['status' => 'error', 'message' => 'This reset session has expired.'], 403);
}
```

A second, shorter clock: ten minutes between verifying the code and choosing
the password. Permission to change a password should not sit around in a
session on a shared computer all afternoon.

Then the familiar parts:

```php
if (strlen($password) < 8)      { ... }
if ($password !== $confirm)     { ... }

$newHash = password_hash($password, PASSWORD_DEFAULT);

$pdo->beginTransaction();

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([$newHash, $userId]);

$pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
    ->execute([$resetId]);

$pdo->prepare(
    'DELETE FROM password_resets
     WHERE user_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())'
)->execute([$userId]);

$pdo->commit();
```

Three writes, one transaction (lesson 04). The worst outcome to avoid is
changing the password but leaving the code usable.

**`used_at = NOW()`** is the one-time use. Replay the same six digits
afterwards and step 2 will not find them, because it only looks
`WHERE used_at IS NULL`.

**The `DELETE`** is the cleanup the requirement asks for: spent and stale
rows go. In a bigger system you would also run a scheduled job to clear rows
for users who never completed a reset.

Finally:

```php
logout_user();
```

The password changed, so every session for that account is suspect —
including this one. Forcing a fresh login with the new password is standard,
and it is what protects someone whose account was accessed by a person who
has now been locked out.

---

## 8. The front end

[public/assets/js/reset.js](../public/assets/js/reset.js) — three submit
handlers and one shared helper:

```js
async function callApi(url, payload) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return await response.json();
    } catch (error) {
        return { status: 'error', message: 'Network problem. Please try again.' };
    }
}
```

Exactly the same mechanics as lesson 09 — which is the point. Learn the
pattern once, then three new endpoints cost almost nothing.

Switching steps is plain DOM work:

```js
function showStep(stepToShow) {
    [stepEmail, stepOtp, stepPassword].forEach(function (step) {
        step.hidden = (step !== stepToShow);
    });
}
```

The email typed in step 1 is kept in a variable so the user does not retype
it. That is convenience only — the server never trusts it for identity,
because after step 2 identity lives in the session.

---

## 9. Try to break it

Work through every row:

| attempt | expected |
|---|---|
| right code, in time | verified, then password updates |
| wrong code | `invalid`, attempts +1 |
| wrong code 6 times | `invalid` and the code is dead |
| request a new code, then use the old one | `invalid` (the old one was marked used) |
| use a code twice | second time `invalid` |
| an email that is not registered | same "code is on its way" message |
| 4 requests in 15 minutes | `429` |

And the expiry, which you can force in SQL:

```sql
UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE
WHERE used_at IS NULL;
```

Now verify with the correct code. You should get `expired` and be sent back
to step 1.

Finally, the one that matters most:

```bash
curl -X POST http://localhost/nxtwave-portal/api/reset-password.php \
     -H "Content-Type: application/json" \
     -d '{"password":"hacked12345","confirm_password":"hacked12345"}'
```

No session, no cookie, no verified OTP. Expected: `403`. If this ever
succeeds, anyone on the internet can change any password.

---

## 10. Check yourself

- [ ] You can explain why `random_int()` and not `rand()`.
- [ ] You can explain why the OTP is stored hashed.
- [ ] You can explain why MySQL computes `expires_at` and not PHP.
- [ ] A used code cannot be reused; an expired code is rejected.
- [ ] `password_resets` cleans itself up after a completed reset.
- [ ] The curl call above returns 403.

All three tasks of Module 1 are done. One lesson left, and it is the one
that ties everything together.

→ [Lesson 12 — Security review](12-security-review.md)
