# Lesson 04 — PDO, prepared statements, and SQL injection

**Goal:** connect PHP to MySQL, check whether an email is taken, and insert
the user — without opening the door that has been used to steal more data
than any other bug in web history.

---

## 1. Connecting

```php
$dsn = "mysql:host=127.0.0.1;dbname=nxtwave_portal;charset=utf8mb4";

$pdo = new PDO($dsn, 'root', '', $options);
```

**PDO** = PHP Data Objects. It is the standard database layer: the same API
whether you are talking to MySQL, PostgreSQL or SQLite. (You will also meet
`mysqli` in older tutorials. It works, but it is MySQL-only and its syntax is
clumsier. New code uses PDO.)

> **Compared to what you know**
> `new PDO(...)` is `psycopg2.connect(...)` / `sqlite3.connect(...)` in
> Python, or `mysql.createConnection(...)` in Node. Same object, same job.

The DSN is just a string describing what to open. Note `charset=utf8mb4` —
leave it out and text arrives mangled in ways that are miserable to debug.

### The three options that matter

Look at [config/database.php](../config/database.php):

```php
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
```

**`ERRMODE_EXCEPTION`** — when a query fails, throw. Without this, PDO
returns `false` and carries on, so a typo in a column name shows up three
functions later as "why is this variable empty". Fail loudly, fail early.

**`FETCH_ASSOC`** — rows come back as `['email' => '...']` and not also as
`[0 => '...']`. Half the memory, and `$row['email']` reads better than
`$row[3]`.

**`EMULATE_PREPARES => false`** — send the query and the values to MySQL
separately, for real, instead of letting PDO paste them together in PHP.
This is the setting that makes prepared statements genuinely safe.

### One connection, included everywhere

```php
require_once __DIR__ . '/../includes/bootstrap.php';
```

The bootstrap from lesson 00 loads `config/database.php`, so every page that
starts with that one line already has `$pdo` waiting. `require_once` means
thirteen files can each ask for the connection and only one gets made.

If the connection fails, look at what that file does:

```php
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());

    abort(503, 'The portal is temporarily unavailable. Please try again in a minute.');
}
```

The real message goes to the log, and the visitor gets a plain sentence.
That is not politeness — `$e->getMessage()` contains the database name, the
username and the host. Printing it on a live page hands a stranger the first
three things they would otherwise have to guess.

While you are learning you will want to see it. That is what `DEBUG` in
`bootstrap.php` is for: PHP's own errors go to the screen, but this one
message stays deliberately vague either way, because a broken database is
exactly the moment you are least likely to be watching.

---

## 2. The injection problem — see it first

Here is the version of the email check that looks reasonable:

```php
$sql = "SELECT id FROM users WHERE email = '$email'";      // NEVER
$result = $pdo->query($sql);
```

Now stop treating `$email` as an email. It is a string that a stranger types,
and it is being glued into the middle of a command.

If someone submits:

```
' OR '1'='1
```

the string that MySQL receives is:

```sql
SELECT id FROM users WHERE email = '' OR '1'='1'
```

`'1'='1'` is always true, so this returns every user in the table.

The same trick on the login query returns the first user — usually the admin
— and logs the attacker in as them. And because MySQL accepts more than one
statement in some configurations, a cheerful variant is:

```
x'; DROP TABLE users; --
```

The `--` comments out whatever your code had after the value, so the rest of
your query never runs.

**Try it.** Seriously — build the broken version once in class, in a throwaway
file, and type `' OR '1'='1` into the form. Students who have watched a table
dump itself never forget the fix.

---

## 3. The fix: prepared statements

```php
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();
```

This is two separate conversations with MySQL:

```
prepare()   "Here is a query shape. There is one value, and it goes here."
            MySQL parses and plans it NOW, while the value is still unknown.

execute()   "The value is:  ' OR '1'='1"
            MySQL puts it in the hole. It cannot become SQL, because the
            parsing already happened.
```

That is the whole idea, and it is why the fix is complete rather than
partial. The value never gets a chance to be read as code. You are not
filtering dangerous characters — there are no dangerous characters, because
the data and the command travel separately.

> **Compared to what you know**
> Python's `cursor.execute("SELECT ... WHERE email = %s", (email,))` is the
> same mechanism. Node's `connection.execute('... WHERE email = ?', [email])`
> too. Every language has this, and in every language people still
> concatenate. Do not.

### Two placeholder styles

```php
// positional - order matters
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND status = ?');
$stmt->execute([$email, 'active']);

// named - order does not matter
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute([':email' => $email]);
```

Use `?` for one or two values. Use `:names` when there are fifteen, like our
big INSERT — counting question marks across four lines is how you end up
storing a phone number in the city column.

### What you cannot parameterise

Placeholders stand in for **values only**. This does not work:

```php
$pdo->prepare('SELECT * FROM users ORDER BY ?');       // no
$pdo->prepare('SELECT * FROM ? WHERE id = 1');         // no
```

Column names, table names and `ASC`/`DESC` are part of the query's structure.
If you ever need those to be dynamic, you are back to the allow list from
lesson 02:

```php
$allowedSort = ['created_at', 'full_name'];
$sort = in_array($_GET['sort'] ?? '', $allowedSort, true) ? $_GET['sort'] : 'created_at';
$sql  = "SELECT * FROM users ORDER BY $sort";      // safe: $sort can only be one of two strings
```

---

## 4. Getting results out

```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
```

| call | gives you |
|---|---|
| `$stmt->fetch()` | the next row as an array, or `false` when there are none |
| `$stmt->fetchAll()` | every row, as an array of arrays |
| `$stmt->fetchColumn()` | just the first column of the first row |
| `$stmt->rowCount()` | how many rows an UPDATE/DELETE changed |

```php
$user = $stmt->fetch();

if ($user) {
    echo $user['full_name'];
}
```

`fetch()` returning `false` for "nothing found" is the idiom you will use in
every lookup in this project. It reads naturally as `if (!$user)`.

`fetchColumn()` is perfect for one-value answers. Here is the version
students write first, and it does not work:

```php
$roleId = (int) $pdo->prepare('SELECT id FROM roles WHERE name = ?')
                    ->execute([$type]);            // gives you true, not the id
```

`execute()` returns a boolean — did the query run — not the statement. So
you cannot chain off it. Write the three lines:

```php
$stmt = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
$stmt->execute([$type]);
$roleId = (int) $stmt->fetchColumn();
```

When you do not need placeholders at all, `query()` runs and returns in one
step — safe here only because there is no user input anywhere in the string:

```php
$roles = $pdo->query('SELECT * FROM roles')->fetchAll();
```

---

## 5. Build it: the email uniqueness check

```
User submits email
       ↓
SELECT id FROM users WHERE email = ?
       ↓
fetch() gave us a row?
 ├── yes → "An account with this email already exists."
 └── no  → continue registering
```

In `public/register.php`:

```php
if ($email !== '' && is_valid_email($email)) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        $errors[] = 'An account with this email already exists.';
    }
}
```

We select `id` and not `*` — we only need to know *whether* a row exists, so
there is no reason to drag fourteen columns across.

This is also where the `UNIQUE` constraint from lesson 01 earns its place.
Two people can submit the same email in the same millisecond, both pass this
check, and only one survives the INSERT. Checking in PHP is for the message;
the constraint is for the truth.

---

## 6. Build it: the INSERT

```php
$sql = 'INSERT INTO users
            (role_id, full_name, email, phone, city, password_hash,
             college, degree, graduation_year, tech_stack,
             company_name, work_email, company_size, designation, status)
        VALUES
            (:role_id, :full_name, :email, :phone, :city, :password_hash,
             :college, :degree, :graduation_year, :tech_stack,
             :company_name, :work_email, :company_size, :designation, :status)';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':role_id'   => $roleId,
    ':full_name' => $fullName,
    // ...
    ':college'   => $college,          // null for an HR user
    ':status'    => $status,
]);
```

The role-specific variables were set to `null` at the top of the POST block
and only filled in for the matching role. PHP `null` becomes SQL `NULL`
automatically — no special handling.

### Getting the new id

```php
$userId = (int) $pdo->lastInsertId();
```

`AUTO_INCREMENT` decided the id inside MySQL, so PHP has to ask for it. We
need it immediately, to attach the three documents to this user (lesson 07).

---

## 7. Transactions — all of it, or none of it

Registration writes **four** rows: one user and three documents. What if the
third document fails?

Without a transaction you get a user with two documents, and no code path
anywhere that can fix it. Data like that outlives the bug by years.

```php
try {
    $pdo->beginTransaction();

    // INSERT the user
    // INSERT document 1, 2, 3

    $pdo->commit();          // now it is real
} catch (Exception $e) {
    $pdo->rollBack();        // pretend none of it happened
    $errors[] = 'Registration failed: ' . $e->getMessage();
}
```

Between `beginTransaction()` and `commit()`, nobody else sees your rows. On
`rollBack()`, MySQL discards every change made since the start.

> **Compared to what you know**
> Python's `conn.commit()` / `conn.rollback()` is exactly this, and Django's
> `@transaction.atomic` is the same thing with a decorator.

### try / catch / throw

```php
throw new RuntimeException('Could not save ' . $doc['label'] . '.');
```

`throw` abandons the current line and jumps to the nearest `catch`. That is
what makes the rollback reliable: you do not need an `if` after every single
INSERT, because any failure — including the exceptions PDO throws for us,
thanks to `ERRMODE_EXCEPTION` — lands in the same place.

`catch (Exception $e)` catches everything; `$e->getMessage()` is the text.

> Same keywords as JavaScript. Python spells it `try/except/raise`.

---

## 8. Try to break it

1. Register with `' OR '1'='1` as the full name. It should save that exact
   text as a name, harmlessly. Check the row in phpMyAdmin.
2. Register two accounts with the same email. Second one must be refused.
3. In `config/database.php`, change the database name to something wrong.
   You should get a clear connection error, not a blank page.
4. Temporarily `throw new RuntimeException('boom');` right after the user
   INSERT, inside the `try`. Submit. Then check `SELECT * FROM users` — the
   row must **not** be there. That is `rollBack()` doing its job. Remove the
   line afterwards.

---

## 9. Check yourself

- [ ] You can explain, without notes, why `prepare()` + `execute()` is safe
      and string concatenation is not.
- [ ] You know the difference between `fetch()`, `fetchAll()` and
      `fetchColumn()`.
- [ ] You know why placeholders cannot be used for a table name.
- [ ] You saw a rollback undo a real INSERT.

One thing is still badly wrong: the password is going into the database as
plain text. Next lesson.

→ [Lesson 05 — Password hashing](05-password-hashing.md)
