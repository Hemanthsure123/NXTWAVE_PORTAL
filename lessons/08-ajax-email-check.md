# Lesson 08 — AJAX: checking the email without reloading

**Goal:** when the user leaves the email box, tell them immediately whether
that address is taken. No page reload, no lost form data.

This is the first time JavaScript enters the project. We only learn the
parts needed to talk to PHP.

---

## 1. The problem, felt rather than described

Do this live:

1. Fill in the whole learner form. All ten fields. Attach three documents.
2. Press submit.
3. "An account with this email already exists."
4. The page reloads. The three file inputs are empty again, because browsers
   will not re-fill a file input (imagine if a page could).

Now the user re-attaches everything. That is the experience we are fixing.

The fix is to ask the server *while they are still filling the form.* But a
normal request replaces the whole page. We need a way to ask a question and
get an answer without leaving.

---

## 2. What AJAX actually is

> AJAX = JavaScript sends an HTTP request in the background and does
> something with the reply, without navigating away.

That is the entire concept. The name is a historical acronym (Asynchronous
JavaScript And XML) and nobody sends XML any more — we send JSON.

```
Normal request                    AJAX request
--------------                    ------------
browser navigates                 page stays exactly where it is
server returns a whole page       server returns a small piece of data
everything is re-drawn            JavaScript updates one element
```

Our flow:

```
Email input loses focus
        ↓
JavaScript fetch()
        ↓
GET api/check-email.php?email=asha@example.com
        ↓
PHP  →  MySQL  →  PHP
        ↓
{"available": true, "message": "Email is available"}
        ↓
JavaScript writes into <div id="email-message">
```

---

## 3. The PHP side first — an endpoint, not a page

Create [api/check-email.php](../api/check-email.php). The important thing is
what it does *not* contain: no `<html>`, no header include, no CSS. It
answers with data.

```php
require_once __DIR__ . '/../includes/bootstrap.php';

$email = strtolower(get_field('email'));

if (!is_valid_email($email)) {
    json_response(['available' => false, 'message' => 'That does not look like an email address']);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);

if ($stmt->fetch()) {
    json_response(['available' => false, 'message' => 'Email already registered']);
}

json_response(['available' => true, 'message' => 'Email is available']);
```

Everything here you already know from lesson 04. The only new part is how it
replies.

### `json_response()`

```php
function json_response(array $data, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
```

Four lines, each doing one job:

- **`http_response_code()`** — the status. `200` means fine. `405` means
  "wrong method". `429` means "slow down". The status is a machine-readable
  summary that sits alongside your message.
- **`header('Content-Type: ...')`** — tells the browser these bytes are JSON.
  Without it the browser assumes HTML, and `response.json()` may still work
  but you are lying about your own data. Set it.
- **`json_encode()`** — turns a PHP array into a JSON string. Associative
  arrays become objects, list arrays become arrays.
- **`exit`** — stop. Anything echoed after the JSON becomes part of the
  response body and breaks the parse on the other side.

> **Compared to what you know**
> `json_encode()` is `JSON.stringify()` in JavaScript and `json.dumps()` in
> Python. `json_decode($s, true)` is `JSON.parse()` / `json.loads()`. The
> `true` in `json_decode` means "give me an array, not an object" — leave it
> out and you get `$data->email` instead of `$data['email']`.

**`header()` must come before any output.** One blank line before your
`<?php` and PHP has already started sending the body, so the header is too
late and you get "headers already sent". This is the concrete reason lesson
00 told you never to write a closing `?>` in a pure-PHP file.

### Test it with no JavaScript at all

Open the URL directly:

```
http://localhost/nxtwave-portal/api/check-email.php?email=asha@example.com
```

You should see raw JSON in the browser. **Get this working before writing a
single line of JavaScript.** When something breaks later, this is how you
find out which half is wrong.

Or from the terminal:

```bash
curl "http://localhost/nxtwave-portal/api/check-email.php?email=asha@example.com"
```

---

## 4. The JavaScript side

Only four concepts are needed.

### a) Find the element

```js
const emailInput = document.getElementById('email');
const emailMessage = document.getElementById('email-message');
```

The **DOM** is the browser's live object model of the page. `getElementById`
hands you one node so you can read `.value` or write `.textContent`.

### b) React to an event

```js
emailInput.addEventListener('blur', checkEmail);
```

An **event** is something that happened: a click, a keystroke, a form
submit. `blur` fires when an element loses focus — exactly the moment the
user has finished typing their email and moved on. (Its opposite is
`focus`.)

`addEventListener('blur', checkEmail)` — note there are no parentheses after
`checkEmail`. We are handing over the function itself, not calling it now.
This trips up everyone once.

### c) Send the request

```js
const url = '../api/check-email.php?email=' + encodeURIComponent(email);
const response = await fetch(url);
const data = await response.json();
```

`fetch()` makes an HTTP request from JavaScript. `encodeURIComponent()`
escapes `&`, `+`, spaces and so on, so the address survives being inside a
URL.

Why two `await`s? `fetch` resolves as soon as the response *headers* arrive
— at that point you know the status, but the body may still be streaming.
`.json()` waits for the rest of the body and parses it.

### d) async / await

A **Promise** is an object meaning "a value that is not here yet". `await`
pauses *this function* until it arrives, while the rest of the page carries
on — the user can keep typing, scrolling, clicking. A function containing
`await` must be declared `async`.

```js
async function checkEmail() {
    ...
    const response = await fetch(url);
}
```

> If you have seen Python's `async def` / `await`, it is the same shape and
> the same reason.

### Always handle failure

```js
try {
    const response = await fetch(url);
    const data = await response.json();
    show(data.message, data.available ? 'ok' : 'bad');
} catch (error) {
    show('Could not check right now', 'busy');
}
```

Wifi dies. The server 500s and returns an HTML error page, which
`response.json()` cannot parse. Without the `catch`, the failure is silent
and the user stares at "Checking…" forever.

The full file is [public/assets/js/register.js](../public/assets/js/register.js)
— about 40 lines including comments.

---

## 5. Two things students get wrong here

### "The AJAX check means I can drop the PHP check"

No. Look at `register.php`: the duplicate-email query is still there in the
POST handler, and the `UNIQUE` constraint is still on the column.

The AJAX check is a **courtesy**. It runs in a browser you do not control,
it can be skipped entirely with curl, and even for an honest user there is a
gap between the check and the submit in which someone else can register that
address.

```
Convenience   AJAX check while typing
Correctness   PHP check on submit
Guarantee     UNIQUE constraint in MySQL
```

Three layers, three different jobs. This is the same "defence in depth" idea
as the uploads `.htaccess`.

### "Why is my response empty / why does `.json()` throw?"

Because the endpoint printed something it should not have. A stray
`print_r()`, a PHP warning, a blank line before `<?php` — any of it lands in
the body and the JSON no longer parses. Open the endpoint URL directly and
look at the raw output. That habit will save more time than any other in
this lesson.

The Network tab (F12 → Network → click the request → Response) shows exactly
what came back, which is the same information with fewer clicks once you are
used to it.

---

## 6. Registration is now complete

Put the whole flow on the board — every step is something the class built:

```
Link with ?type=            lesson 02
    ↓
Render the right form       lesson 03
    ↓
Live email check            lesson 08  ← today
    ↓
POST everything             lesson 03
    ↓
Validate all fields         lesson 03
    ↓
Check email in MySQL        lesson 04
    ↓
Validate 3 files            lesson 06
    ↓
Hash the password           lesson 05
    ↓
BEGIN TRANSACTION           lesson 04
  INSERT user
  move files + INSERT documents
COMMIT                      lesson 07
    ↓
Success screen
```

Task 1.1 is done. That is a lot of PHP, and not one topic of it was learned
in isolation.

---

## 7. Check yourself

- [ ] Opening `api/check-email.php?email=...` directly shows JSON.
- [ ] Typing an existing email in the form shows a red message on blur, with
      no page reload.
- [ ] Turning off JavaScript entirely still leaves registration correct.
- [ ] You can explain why the server check cannot be removed.
- [ ] You can explain what `await` is waiting for, twice.

→ [Lesson 09 — Login as a JSON API](09-login-json-api.md)
