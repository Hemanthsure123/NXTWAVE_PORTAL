# Lesson 09 — Login as a JSON API

**Goal:** verify a password and answer with one of three structured replies:
`success`, `invalid_credentials`, `account_pending_review`.

This lesson is short, because everything in it is something you already
built. That is the point — notice how much faster this goes.

---

## 1. What authentication is

**Authentication** answers "who are you?"
**Authorization** answers "are you allowed to do this?"

Today is authentication only. Authorization is lesson 10, and the difference
matters enough that mixing them up is how access-control bugs happen.

The flow:

```
email + password
      ↓
SELECT the user by email
      ↓
no such user? ──────────────► invalid_credentials
      ↓ found
password_verify()
      ↓
wrong? ─────────────────────► invalid_credentials
      ↓ correct
status = 'active'?
      ↓ no ─────────────────► account_pending_review
      ↓ yes
create the session, tell the browser where to go
```

---

## 2. Finding the user

```php
$sql = 'SELECT  u.id, u.full_name, u.email, u.password_hash, u.status,
                r.name AS role_name
        FROM    users u
        JOIN    roles r ON r.id = u.role_id
        WHERE   u.email = ?';

$stmt = $pdo->prepare($sql);
$stmt->execute([$email]);

$user = $stmt->fetch();
```

The `JOIN` from lesson 01, doing exactly the job it was designed for: we
have `role_id` in `users` but we want the word `'learner'`, which lives in
`roles`. `AS role_name` means PHP reads it as `$user['role_name']`.

We name the columns instead of `SELECT *`. Two reasons: you do not drag
fifteen columns across for no reason, and you can see at a glance exactly
what this endpoint has access to.

`fetch()` gives an array or `false`.

---

## 3. Verifying the password

```php
if (!$user || !password_verify($password, $user['password_hash'])) {
    json_response([
        'status'  => 'invalid_credentials',
        'message' => 'Invalid email or password',
    ]);
}
```

`password_verify()` is the other half of lesson 05. It pulls the algorithm,
cost and salt out of the stored hash, hashes the typed password the same
way, and compares — in constant time.

### Why one message for two different failures

This always starts a good argument in class. "Wouldn't it be friendlier to
say *no account with that email*?"

Friendlier, yes. It also turns your login page into a tool for checking
whether an address has an account here:

```
try  ceo@bigcompany.com   → "no account with that email"    not a customer
try  hr@bigcompany.com    → "wrong password"                IS a customer
```

That is called **user enumeration**, and the output is a verified list of
customers — useful for phishing, and for credential stuffing with passwords
leaked from other sites. One message for both cases closes it.

(Notice the same reasoning in `api/request-otp.php`, where we say "if that
email is registered, a code is on its way" whether or not it exists.)

---

## 4. The third answer

```php
if ($user['status'] !== 'active') {
    json_response([
        'status'  => 'account_pending_review',
        'message' => 'Your account is waiting for NxtWave approval',
    ]);
}
```

The password was right. This is not a credentials problem, it is a state
problem, and telling the user "invalid password" here would be a lie that
generates a support ticket.

This is the `status` column from lesson 01 finally paying off. Learners are
inserted as `'active'`; corporate HR as `'pending'` until an employee
approves them on the admin dashboard.

Order matters: we check the password **first**. Checking status before the
password would leak which emails belong to pending accounts.

---

## 5. Why three named statuses instead of true/false

```json
{ "status": "success",                "redirect": "learner-dashboard.php" }
{ "status": "invalid_credentials",    "message": "Invalid email or password" }
{ "status": "account_pending_review", "message": "Your account is waiting for NxtWave approval" }
```

Compare with what beginners usually write:

```json
{ "success": false, "message": "Your account is waiting for approval" }
```

The difference: in the second version, the only way for JavaScript to react
differently is to read the English sentence. Change the wording, or
translate the site to Telugu, and the front end breaks.

With a named `status`, the front end switches on a value that never changes,
and the `message` is free to be rewritten by anyone:

```js
if (data.status === 'success') { ... }
if (data.status === 'account_pending_review') { ... }   // show blue, not red
```

**`status` is for the machine. `message` is for the human.** Keep them
separate and both stay easy to change.

---

## 6. The front end

[public/assets/js/login.js](../public/assets/js/login.js):

```js
form.addEventListener('submit', async function (event) {
    event.preventDefault();
    ...
});
```

`event.preventDefault()` is the line that stops the browser doing its
default thing — submitting the form and reloading the page. Forget it and
your fetch is cancelled mid-flight by the navigation, which produces a
wonderfully confusing "sometimes it works" bug.

Sending JSON instead of form fields:

```js
const response = await fetch('../api/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, password: password })
});
```

The second argument to `fetch` is an options object: method, headers, body.
The body must be a string, so `JSON.stringify`.

### Reading a JSON body in PHP

Here is a thing that surprises everybody:

```php
print_r($_POST);     // Array ( )  — empty!
```

`$_POST` is only populated for form encodings
(`application/x-www-form-urlencoded` and `multipart/form-data`). We sent
`application/json`, so PHP does not parse it. Read the raw body yourself:

```php
function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}
```

`php://input` is a stream containing the untouched request body.
`json_decode($raw, true)` turns it into an array. The `is_array()` guard
handles a malformed body — `json_decode` returns `null` on bad JSON, and
without the guard the next line would fatal.

### Disable the button while waiting

```js
button.disabled = true;
button.textContent = 'Checking...';
```

Otherwise an impatient user sends five login requests. Small touch, and it
is the same reasoning as rate limiting, just on the polite side.

---

## 7. Why login is a POST

```php
if (!is_post()) {
    json_response(['status' => 'error', 'message' => 'Use POST'], 405);
}
```

A GET would put the password in the URL: browser history, server access
logs, the `Referer` header sent to any third party, and the link your user
pastes into a chat. `405` is the HTTP status for "method not allowed".

Note the difference from lesson 08: `check-email.php` is a GET because it
only reads and reveals nothing sensitive; `login.php` is a POST because it
carries a secret and creates a session.

---

## 8. Test the endpoint on its own

Before touching the page, prove the API works:

```bash
curl -X POST http://localhost/nxtwave-portal/api/login.php \
     -H "Content-Type: application/json" \
     -d '{"email":"asha@example.com","password":"secret12345"}'
```

Run all four cases:

| input | expected `status` |
|---|---|
| correct learner credentials | `success` |
| correct email, wrong password | `invalid_credentials` |
| email that does not exist | `invalid_credentials` |
| correct HR credentials, still pending | `account_pending_review` |

Endpoint first, page second. Every time.

---

## 9. Check yourself

- [ ] All four curl cases return the right `status`.
- [ ] Logging in on the page shows no reload at all.
- [ ] You can explain user enumeration and how we avoid it.
- [ ] You can explain why `$_POST` is empty when the body is JSON.
- [ ] You can explain the difference between `status` and `message`.

The endpoint currently says "success" and then… nothing. The next request
has already forgotten who you are.

→ [Lesson 10 — Sessions and role based access](10-sessions-and-roles.md)
