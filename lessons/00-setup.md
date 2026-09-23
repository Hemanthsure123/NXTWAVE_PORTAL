# Lesson 00 — Setup and the shape of a PHP app

**Goal:** understand what PHP actually does, and create the folders we will
fill for the rest of the module.

Do not spend an hour here. Twenty minutes, and most of it while typing.

---

## 1. Who does what

Open any website and two computers are involved.

```
   BROWSER (client)                     SERVER
   your laptop                          some machine in a datacenter
   ----------------                     ---------------------------
   HTML, CSS, JavaScript                PHP, MySQL
   knows how to draw things             knows your data
```

The browser can never touch the database. It can only *ask*. PHP sits in the
middle and decides what to answer.

One request, start to finish:

```
You type   http://localhost/nxtwave-portal/public/login.php
   │
   ▼
Apache sees a .php file, hands it to PHP
   │
   ▼
PHP runs the file top to bottom
   │  - maybe asks MySQL for something
   │  - builds a string of HTML
   ▼
Apache sends that HTML back
   │
   ▼
Browser draws it
```

By the time you see the page, **PHP is already finished and gone**. It does
not sit there waiting. Every click starts a brand new run of the script, with
no memory of the last one. Remember that sentence — in lesson 10 it becomes
the whole reason sessions exist.

> **Compared to what you know**
> If you have written Node, PHP is like a tiny Express app that is created
> and destroyed on every request. If you have written Python, it is like a
> Flask view function that runs and then the whole interpreter exits.

---

## 2. Your first file

Create `public/hello.php`:

```php
<?php
echo "Hello from the server";
```

Open `http://localhost/nxtwave-portal/public/hello.php`.
Then press **Ctrl+U** (view source). You will not find `echo` anywhere. The
browser never saw the PHP, only the result. That is the point.

Now mix PHP into HTML:

```php
<h1>Today is <?php echo date("d M Y"); ?></h1>
```

`<?php` opens PHP, `?>` closes it. Everything outside those tags is printed
as-is. There is a shortcut for "open PHP, echo one thing, close PHP":

```php
<h1>Today is <?= date("d M Y") ?></h1>
```

You will see `<?=` everywhere in this project, because HTML templates are
full of tiny one-value echoes.

**Rule to memorise now:** a file that is pure PHP (like `config/database.php`)
gets `<?php` at the top and **no** closing `?>` at the bottom. A stray blank
line after `?>` gets sent to the browser and will later break `header()` calls
in a way that is genuinely painful to debug.

---

## 3. Splitting the project into folders

We could write all 2000 lines in one file. Let's not.

```
config/     settings: database, mail            (never opened by a browser)
includes/   reusable PHP: helpers, auth, layout (never opened by a browser)
public/     pages people actually visit
api/        endpoints that answer with JSON instead of HTML
uploads/    files users send us (closed to the browser)
bin/        command line scripts, never opened in a browser
vendor/     PHPMailer, installed by composer
sql/        the schema
```

The split is not decoration. `config/database.php` contains a password. Pages
in `public/` are meant to be opened by strangers. Keeping them in different
folders makes it obvious which is which.

Create them now:

```
nxtwave-portal/
├── config/
├── includes/
├── public/assets/css
├── public/assets/js
├── api/
├── uploads/profile
├── uploads/resume
├── uploads/documents
├── vendor/          (composer creates this)
├── sql/
└── bin/             command line scripts
```

---

## 4. include and require

PHP has four ways to pull one file into another:

```php
include  'file.php';        // missing file -> warning, script keeps going
require  'file.php';        // missing file -> fatal error, script stops
include_once 'file.php';    // same, but never loads it twice
require_once 'file.php';    // same, but never loads it twice
```

How to choose, in one line each:

- The app cannot work without it (database, functions) → `require_once`.
- It is a piece of layout and the page could limp on without it → `include`.

> **Compared to what you know**
> `require_once` is PHP's `import` (Python) or `require()` (Node) — except it
> does not create a namespace. Everything in the included file (variables,
> functions) just lands in the current file. That is why our
> `config/database.php` can create `$pdo` and `register.php` can use it
> directly.

### `__DIR__` — the thing that saves you an hour

```php
require_once 'config/database.php';           // relative to whoever is running
require_once __DIR__ . '/../config/database.php';   // relative to THIS file
```

`__DIR__` is the folder of the file the line is written in. Since
`public/register.php` and `api/check-email.php` sit at different depths, only
the `__DIR__` version works from both. Use it always.

The `.` is PHP's string joiner:

```php
$a = "nxt" . "wave";        // "nxtwave"
```

> JavaScript uses `+`. Python uses `+`. PHP uses `.` because `+` is reserved
> for maths. `"5" + "5"` is `10` in PHP, and `"55"` in JavaScript. Interesting
> quiz question, terrible thing to rely on.

---

## 5. One include to start them all

Every page needs the helpers, the session functions and the database. Rather
than four `require_once` lines at the top of thirteen files, there is one:

```php
require_once __DIR__ . '/../includes/bootstrap.php';
```

Open [includes/bootstrap.php](../includes/bootstrap.php) and it is short:

```php
const DEBUG = true;

ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
```

Two reasons this file exists, and only one of them is tidiness.

The real reason is that an application needs **one place for settings that
apply everywhere**. `DEBUG` is the example that matters: while you are
building, you want PHP errors on the screen. On a live site you want them in
a log file, because a stack trace tells a stranger your folder layout, your
database name and often a whole query. One constant, one place, both
behaviours.

`date_default_timezone_set()` is there for the same reason. Leave it out and
`date()` may disagree with MySQL's clock about what time it is, which is
exactly the kind of bug that only appears in production, on a server in
another country.

Files that need something extra still ask for it:

```php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload.php';     // only file pages
```

---

## 6. The layout include

Every page in this project needs the same `<head>`, header bar and footer. So
we wrote them once: `includes/header.php` and `includes/footer.php`.

A page then looks like:

```php
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Login';
include __DIR__ . '/../includes/header.php';
?>

<h1>Login</h1>

<?php include __DIR__ . '/../includes/footer.php'; ?>
```

Notice `$pageTitle` is set *before* the include. The included file can see
every variable that exists at the point where you include it. That is how
data gets passed into a template in plain PHP — no arguments, just variables.

---

## 7. Check yourself

- [ ] `public/hello.php` runs and view-source shows no PHP.
- [ ] You can explain why the browser cannot talk to MySQL directly.
- [ ] You can say what `__DIR__` is for without looking it up.
- [ ] You can explain what `DEBUG` in bootstrap.php changes, and why it
      matters on a live site.
- [ ] Your folder tree matches the list above.

Delete `hello.php` when you are done. Next lesson we design the database,
because there is no point writing a registration form when there is nowhere
to put the registration.

→ [Lesson 01 — Database first](01-database.md)
