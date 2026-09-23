# Lesson 10 — Sessions, role based redirects, and real authorization

**Goal:** make the server remember who is logged in, send each role to its
own dashboard, and make sure nobody can open a page that is not theirs.

---

## 1. HTTP forgets everything

Back to lesson 00: PHP runs, produces a response, and exits. The next click
starts a brand new process that has never heard of you.

So `api/login.php` verified a password — and then died. Open
`learner-dashboard.php` and PHP has no idea anyone logged in.

This is not a flaw. **HTTP is stateless** by design, which is what lets any
server in a cluster answer any request. But an application needs memory, so
we add it on top.

### The idea

```
Browser                                   Server
   │                                         │
   │  POST /api/login.php  (correct)         │
   │────────────────────────────────────────►│  creates a file:
   │                                         │  sess_8f3a2b...
   │                                         │  { user_id: 101, role: learner }
   │  ◄──── Set-Cookie: PHPSESSID=8f3a2b... ─│
   │                                         │
   │  GET /learner-dashboard.php             │
   │  Cookie: PHPSESSID=8f3a2b...  ─────────►│  reads sess_8f3a2b...
   │                                         │  "ah, user 101"
```

The browser holds only a random id. All the actual data stays on the server.
That matters: if the cookie contained `role=learner`, the user could edit it
to `role=employee`. A meaningless random id cannot be edited into anything
useful.

> **Compared to what you know**
> Flask's `session` and Express's `express-session` are the same pattern.
> (Flask's default is a signed cookie holding the data — PHP keeps the data
> server-side, which is why our cookie is just an id.)

---

## 2. `session_start()`

```php
session_start();
$_SESSION['user_id'] = 101;
```

`session_start()` does one of two things: reads the incoming `PHPSESSID`
cookie and loads that session file, or creates a new session and sends a
`Set-Cookie` header.

Because it sends a header, **it must run before any output.** Not one
character of HTML, not one blank line before `<?php`. Same rule as
`header()` and `json_response()`, same reason.

Our wrapper in [includes/auth.php](../includes/auth.php):

```php
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' => true,   <- switch on once the site is HTTPS
        ]);
        session_start();
    }
}
```

The `session_status()` check lets ten files call it without a "session
already started" notice.

The cookie flags are small and worth knowing by name:

- **`httponly`** — JavaScript cannot read the cookie. So an XSS bug (lesson
  03) can no longer steal the session id, which is what makes XSS profitable.
- **`samesite=Lax`** — the cookie is not sent on cross-site POSTs. That is
  partial CSRF protection for free.
- **`secure`** — only send over HTTPS. Off on localhost because localhost
  has no certificate; on in production, always.

---

## 3. `$_SESSION`

Just an array. Write to it, read it on the next request.

```php
$_SESSION['user_id']    = (int) $user['id'];
$_SESSION['full_name']  = $user['full_name'];
$_SESSION['role_name']  = $roleName;
$_SESSION['auth_token'] = bin2hex(random_bytes(32));
```

What we chose to store, and why:

- **`user_id`** — everything else can be looked up from it.
- **`full_name`** — shown in the header bar on every page; caching it saves a
  query per request.
- **`role_name`** — the redirect and every page guard need it.
- **`auth_token`** — a random value identifying this specific login. Useful
  later for "log out of all devices", or for tying an API call to a session.

What we deliberately do **not** store: the password hash, the whole user row.
Sessions are small on purpose, and stale data in a session is a bug waiting
to happen — if an employee downgrades someone's role, a fat session keeps
showing the old one until they log out.

To see it, open `C:\xampp\tmp\` and look for `sess_...` files. Read one.
That is your session, in plain text, on disk. (Which is also a reminder that
anyone with server access can read every session.)

---

## 4. Session fixation, and why we regenerate

```php
session_regenerate_id(true);
```

The attack: somebody gets a victim to use a session id *they* already know —
by sending a link with the id in it, or by setting the cookie from an XSS
bug. The victim logs in. The session file becomes "logged in as the victim",
and the attacker already holds the id.

`session_regenerate_id(true)` issues a brand new id at the moment of login
and deletes the old file. Whatever the attacker knew is now worthless.

**Rule: regenerate the id whenever the privilege level changes.** Login is
the obvious one; we also do it in `api/verify-otp.php`, where passing the
OTP check grants a new power.

---

## 5. Role-based redirects

```php
function dashboard_for_role(string $roleName): string
{
    return match ($roleName) {
        'learner'      => 'learner-dashboard.php',
        'corporate_hr' => 'corporate-dashboard.php',
        'employee'     => 'admin-dashboard.php',
        default        => 'login.php',
    };
}
```

`match` arrived in PHP 8 and is the tidier way to pick one value out of
several. Two things it fixes compared with the older `switch`:

```php
switch ($roleName) {
    case 'learner':      return 'learner-dashboard.php';
    case 'corporate_hr': return 'corporate-dashboard.php';
    default:             return 'login.php';
}
```

- `switch` compares loosely (`==`), so `0` would match the string
  `'learner'`. `match` compares with `===`.
- `switch` runs statements and needs a `break` in every case that does not
  `return`. Forget one and it falls through into the next case. `match` is
  an expression: it produces a value, so there is nothing to forget.

The `default` arm matters either way. A role we do not recognise must land
somewhere harmless, not nowhere. Leave `default` out of a `match` and an
unknown value throws an `UnhandledMatchError` instead.

The login endpoint sends the destination in its reply:

```php
json_response([
    'status'   => 'success',
    'redirect' => dashboard_for_role($user['role_name']),
]);
```

and JavaScript follows it:

```js
window.location.href = data.redirect;
```

**The server decides the destination.** The browser is told. Never send the
role to JavaScript and let it choose the page — the point is that the
decision is made somewhere the user cannot edit.

### `header('Location: ...')`

For normal (non-AJAX) pages we redirect in PHP:

```php
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}
```

The `exit` is not optional. `header()` only queues a header; the rest of the
script keeps running and can still print a whole page — including, say, the
admin dashboard you were trying to redirect *away* from. The browser usually
throws that body away. Usually. `curl` does not.

---

## 6. Authorization — the part people get wrong

```php
require_role('learner');
```

Two lines at the top of a page, and everything below is safe.

```php
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function require_role(string $roleName): void
{
    require_login();

    if (($_SESSION['role_name'] ?? '') !== $roleName) {
        abort(403, 'This page is not available for your account.');
    }
}
```

`401` means "I do not know who you are" → go log in.
`403` means "I know who you are, and no" → stop.

`abort()` lives in `includes/functions.php` and does three things: sets the
status code, prints a small page, and stops. Sending a status without an
`exit` is a classic mistake — the code keeps running and the page you were
refusing to show gets printed underneath the 403.

### The sentence to write on the board

> **Hiding a button is not authorization.**

Menus, `if ($isAdmin) { echo '<a href=...>' }`, greyed-out buttons, React
route guards — all of it is *layout*. The user can type the URL. The server
must refuse.

Prove it in class. Log in as a learner, then type the admin dashboard URL
into the address bar. You get 403, from three lines of PHP that do not care
what the menu showed.

Then comment `require_role('employee')` out of `admin-dashboard.php` and do
it again. The learner is now reading the list of pending companies. Put the
line back.

### Every protected page, and every endpoint

The guard goes on **pages** (`learner-dashboard.php`) and on **endpoints**
(anything in `api/` that touches user data). An endpoint is a URL like any
other; leaving it unguarded because "only the dashboard calls it" is how
data leaks.

---

## 7. Logout

```php
function logout_user(): void
{
    start_session();

    $_SESSION = [];                 // 1. empty the data

    if (ini_get('session.use_cookies')) {      // 2. expire the cookie
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();              // 3. delete the file on the server
}
```

All three steps, in that order. Beginners write only step 1, or only step 3.

Step 3 is the one that actually matters: if the session file survives, then
anyone holding that id — from a browser history, a shared machine, a logged
proxy — is still logged in. Deleting the cookie without destroying the
session protects nobody.

---

## 8. Build the dashboards

Three pages, all the same shape:

```php
require_role('learner');

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
```

Look hard at that `WHERE id = ?`. The value comes from `$_SESSION`, which
lives on the server. Compare with the version students write first:

```php
$stmt->execute([$_GET['id']]);      // catastrophic
```

That is an **IDOR** — Insecure Direct Object Reference. Change `?id=101` to
`?id=102` and you are reading someone else's phone number, city and
government ID. It is one of the most common real-world vulnerabilities, and
it is invisible in testing because the developer only ever clicks their own
link.

**Identity comes from the session. Never from the request.**

The employee dashboard (`admin-dashboard.php`) goes a little further and
lets staff approve pending HR accounts:

```php
$stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ? AND status = ?');
$stmt->execute([$decision, $targetId, 'pending']);
```

Note `AND status = 'pending'` in the WHERE clause. A request to approve an
already-rejected account changes nothing, and `rowCount()` tells us so. Put
the condition in the query rather than in an `if` above it — then it is true
even if two employees click at the same moment.

That form also carries a CSRF token, which is explained in lesson 12.

---

## 9. Try to break it

| attempt | expected |
|---|---|
| open `learner-dashboard.php` logged out | bounced to login |
| log in as learner, open `admin-dashboard.php` | 403 |
| log in as HR (approved), open `learner-dashboard.php` | 403 |
| log in, click logout, press Back | bounced to login, not a cached dashboard |
| log in, delete the `PHPSESSID` cookie in F12 → Application | bounced to login |
| POST to `admin-dashboard.php` with curl and no session | not allowed |

---

## 10. Check yourself

- [ ] You can draw the cookie/session diagram from memory.
- [ ] You know why the cookie holds an id and not the role.
- [ ] You can explain session fixation and what `session_regenerate_id()` does.
- [ ] You can explain IDOR and where the user id must come from.
- [ ] All three dashboards refuse the wrong role.
- [ ] Logout does all three steps.

Task 1.2 is complete. One feature left.

→ [Lesson 11 — OTP password reset](11-otp-password-reset.md)
