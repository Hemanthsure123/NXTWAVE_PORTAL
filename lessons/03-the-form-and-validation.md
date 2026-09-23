# Lesson 03 — The form, and why validation lives on the server

**Goal:** build the registration form that changes shape depending on the
role, read what comes back, and check every single field in PHP.

---

## 1. GET decides the form, POST sends the data

This distinction is the spine of the whole lesson.

```
GET  register.php?type=learner     "show me the learner form"
POST register.php?type=learner     "here is a filled-in learner form"
```

Same file, same URL, two different jobs. PHP tells them apart with:

```php
$_SERVER['REQUEST_METHOD']      // 'GET' or 'POST'
```

which we wrapped as `is_post()` in `includes/functions.php`. The whole page
is then shaped like this:

```php
<?php
$type = get_field('type');          // GET data: which form
// ... validate $type ...

if (is_post()) {
    // POST data: the answers
}

// ... print the form ...
```

**Rule:** a GET must not change anything. Opening a link twice, or a browser
pre-fetching it, must be harmless. Anything that creates, updates or deletes
is a POST. This is not a PHP rule — it is how the web works, and every
framework in every language follows it.

---

## 2. The HTML form

```html
<form method="post"
      action="register.php?type=learner"
      enctype="multipart/form-data"
      novalidate>
```

Four attributes, four decisions:

- **`method="post"`** — the data goes in the request body, not the URL. A
  password in a URL ends up in browser history and server logs.
- **`action`** — where it goes. We keep `?type=` in it, because the POST
  request needs to know the role too. Drop it and the form submits to a page
  that no longer knows which role it is.
- **`enctype="multipart/form-data"`** — required for file uploads. Leave it
  out and `$_FILES` arrives empty with no error message whatsoever. This
  costs beginners about an hour, once.
- **`novalidate`** — turns *off* the browser's built-in checking. We do that
  on purpose while learning, so the class sees the PHP validation actually
  fire. In production you would leave HTML validation on as a convenience,
  but never as protection.

### Inputs

```html
<label for="full_name">Full name</label>
<input type="text" id="full_name" name="full_name" value="">
```

The **`name`** attribute is the only one PHP cares about. `name="full_name"`
becomes `$_POST['full_name']`. `id` is for the `<label for=...>` link and for
JavaScript; PHP never sees it.

Input types we use:

| | |
|---|---|
| `type="text"` | names, college, city |
| `type="email"` | shows an @ keyboard on phones |
| `type="tel"` | numeric keypad on phones |
| `type="number"` | graduation year |
| `type="password"` | dots instead of characters |
| `type="file"` | lesson 06 |
| `<select>` | a fixed list of options |

Every one of these is a hint to the *browser*. None of them is a guarantee.
`type="number"` does not mean PHP receives a number — PHP receives the string
`"2k25"` if the user sends it with curl.

### A select, drawn from a PHP array

```php
$degrees = ['B.Tech', 'B.Sc', 'B.Com', 'BCA', 'M.Tech', 'MCA', 'Other'];
```

```php
<select id="degree" name="degree">
    <option value="">-- select --</option>
    <?php foreach ($degrees as $option): ?>
        <option value="<?= e($option) ?>"
            <?= (($_POST['degree'] ?? '') === $option) ? 'selected' : '' ?>>
            <?= e($option) ?>
        </option>
    <?php endforeach; ?>
</select>
```

Three things happen in those six lines:

1. The `<option>` list is generated from the array, so adding a degree is a
   one-word change.
2. The `selected` attribute keeps the user's previous choice after a failed
   submit.
3. The **same `$degrees` array** is used to validate the answer later. If the
   list and the check are two separate pieces of code, they drift apart.

---

## 3. Rendering the role-specific half

The requirement: learners see College / Degree / Graduation Year / Target
Tech Stack; HR sees Company Name / Work Email / Company Size / Designation.
Same file, one `if`.

```php
<?php if ($isLearner): ?>

    <h2>Education</h2>
    <!-- college, degree, graduation_year, tech_stack -->

<?php else: ?>

    <h2>Company</h2>
    <!-- company_name, work_email, company_size, designation -->

<?php endif; ?>
```

That is "conditional rendering" — the same idea as `{condition && <div/>}` in
React, done on the server before the HTML is ever sent. The browser receives
only one version and has no idea the other exists.

Ten fields go out in total: six that everybody fills (name, email, phone,
city, password, confirm password) and four that depend on the link they
clicked. Plus three file inputs, which is lesson 06.

---

## 4. Reading what came back

```php
$fullName = trim($_POST['full_name'] ?? '');
```

Wrapped as `post_field()` so we do not repeat `trim(... ?? '')` fifteen
times. `trim()` matters more than it looks — `"asha@example.com "` with a
trailing space is a different string, and will fail login forever.

One exception, and it is deliberate:

```php
$password = $_POST['password'] ?? '';        // NOT trimmed
```

A space can be a legitimate character in a password. Trimming it silently
changes what the user typed, and then their login fails and nobody knows why.

Before writing any validation, look at the raw data once:

```php
if (is_post()) {
    echo '<pre>';
    print_r($_POST);
    echo '</pre>';
}
```

Submit the form. You will see exactly the array PHP built from your `name`
attributes. Students who do this once stop guessing forever.

---

## 5. Validation

### Why the server, always

Ask the class: "we put `required` on the input, and JavaScript checks it too.
Why check again in PHP?"

Then show them. Open the page, press **F12**, and in the console:

```js
document.querySelector('form').noValidate = true;
document.getElementById('email').value = 'not-an-email';
```

Submit. Every browser-side check is gone in two lines. And that is the polite
version — the real attacker never opens your page at all:

```bash
curl -X POST "http://localhost/nxtwave-portal/public/register.php?type=learner" \
     -F "full_name=x" -F "email=junk" -F "graduation_year=9999"
```

Your HTML, your CSS and your JavaScript are all **suggestions** made to a
program you do not control. PHP is the only code you actually own.

> **The one sentence to write on the board**
> Client-side validation is for user experience. Server-side validation is
> for correctness and security. You need both, and only one of them is
> optional.

### The pattern we use

```php
$errors = [];

if ($fullName === '') {
    $errors[] = 'Full name is required.';
} elseif (mb_strlen($fullName) < 3) {
    $errors[] = 'Full name looks too short.';
}

if (!is_valid_email($email)) {
    $errors[] = 'That email address is not valid.';
}
// ... and so on

if (!$errors) {
    // save it
}
```

Collect *all* the problems, then show them together. A form that reveals one
error at a time is a form people abandon. `if (!$errors)` reads as "if the
array is empty", because an empty array is falsy in PHP.

### The checks, one per kind of data

**Required text** — `=== ''` after trimming.

**Email** — do not write your own regex. Really.

```php
filter_var($email, FILTER_VALIDATE_EMAIL) !== false
```

`filter_var` is PHP's built-in validator and it has already lost all the
arguments about apostrophes and plus signs that you are about to have.

**Phone** — a rule we choose, so a regex is right:

```php
preg_match('/^[6-9][0-9]{9}$/', $phone) === 1
```

Read it left to right: `^` start, `[6-9]` first digit is 6 to 9,
`[0-9]{9}` nine more digits, `$` end. `preg_match` returns `1` on a match,
`0` on no match, and `false` on a broken pattern — which is why we compare
with `=== 1` and not just `if (preg_match(...))`.

**Numbers** — `ctype_digit()` asks "is every character 0-9?"

```php
if (!ctype_digit($graduationYear)) {
    $errors[] = 'Graduation year must be a 4 digit year.';
} else {
    $graduationYear = (int) $graduationYear;     // now safe to cast
    $thisYear = (int) date('Y');

    if ($graduationYear < 1990 || $graduationYear > $thisYear + 5) {
        $errors[] = 'Graduation year must be between 1990 and ' . ($thisYear + 5) . '.';
    }
}
```

Two-step on purpose: check the format first, then the range. `(int) "abc"`
quietly becomes `0`, and `0` passes a naive "is it a number" test.

`(int)` is a **cast** — `int(x)` in Python, `parseInt(x)` in JavaScript.

**Dropdowns** — check against the same array that drew the `<select>`:

```php
if (!in_array($degree, $degrees, true)) {
    $errors[] = 'Please choose a degree from the list.';
}
```

A `<select>` is not a restriction. Anyone can POST `degree=Professor`.

**Password** — length, and that the two boxes match:

```php
if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
} elseif ($password !== $confirm) {
    $errors[] = 'The two passwords do not match.';
}
```

`confirm_password` never reaches the database. It exists only to catch typos.

---

## 6. Showing the errors, and not losing the user's work

```php
<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Please fix the following:</strong>
        <ul>
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

And on every input:

```php
<input type="text" name="full_name" value="<?= old('full_name') ?>">
```

```php
function old(string $key): string
{
    return e($_POST[$key] ?? '');
}
```

A form that wipes itself on error is the fastest way to lose a registration.
Password fields are the exception — we never re-fill those.

---

## 7. `e()` — the function you will use a thousand times

```php
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
```

Why it exists: suppose someone registers with the name

```html
<script>alert('hi')</script>
```

and our dashboard does `echo $user['full_name'];`. The browser has no way to
know that text came from a database — it just sees a `<script>` tag and runs
it. That is **XSS** (cross-site scripting), and on a real site the script
would be stealing session cookies, not saying hi.

`htmlspecialchars` turns `<` into `&lt;`, `>` into `&gt;`, `"` into `&quot;`.
The browser then *displays* the text instead of *executing* it.

`ENT_QUOTES` also escapes single quotes, which matters because we write
`value='<?= ... ?>'` in places. `'UTF-8'` tells it how to read the bytes.

> **The habit:** escaping happens at the moment of output, not on the way
> into the database. Store what the user typed; escape it every time you
> print it. Store-escaped data is a nightmare the day you need to send it as
> JSON, or in an email, or to a PDF generator — each of those needs a
> *different* kind of escaping.

---

## 8. Check yourself

- [ ] `?type=learner` and `?type=corporate_hr` show different middle sections.
- [ ] Submitting an empty form lists every missing field at once.
- [ ] After an error, your typed values are still in the boxes.
- [ ] You disabled the browser validation from the console and PHP still
      caught everything.
- [ ] You can explain XSS and what `e()` does about it.

Right now a valid submission does nothing at all — there is no database code
yet. That is next.

→ [Lesson 04 — PDO, prepared statements, SQL injection](04-pdo-and-sql-injection.md)
