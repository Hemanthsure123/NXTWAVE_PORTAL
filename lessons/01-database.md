# Lesson 01 — Database first

**Goal:** design and create the four tables the whole module will use.

We build the database before the form on purpose. If you start with the form,
you end up inventing columns as you go and the table becomes a junk drawer.
Start with "what do we need to remember", and the form almost writes itself.

---

## 1. Vocabulary, quickly

```
Database   nxtwave_portal          a named box of tables
 └─ Table  users                   one kind of thing
     ├─ Column  email              one fact about that thing
     └─ Row     (12, 'asha@...')   one actual thing
```

A **primary key** is the column that says which row is which. Ours is always
`id`, an `INT` with `AUTO_INCREMENT` — MySQL hands out 1, 2, 3… so we never
have to think about it.

A **foreign key** is a column that points at another table's primary key.
`users.role_id` holds a number that exists in `roles.id`. MySQL will then
refuse to store a `role_id` that does not exist. That refusal is the feature.

### Column types you will use today

| Type | Use it for | Note |
|---|---|---|
| `INT` | ids, years, counts | whole numbers |
| `VARCHAR(n)` | names, emails, paths | text up to n characters |
| `TEXT` | long text | no length limit, cannot be fully indexed |
| `ENUM('a','b')` | a short fixed list | MySQL rejects anything else |
| `DATETIME` | a moment you chose | what we use for `expires_at` |
| `TIMESTAMP` | a moment MySQL chose | `DEFAULT CURRENT_TIMESTAMP` |
| `NULL` | "we do not know" | different from `''` and from `0` |

`NULL` deserves a second read. An empty string means "they told us, and it
was blank". `NULL` means "this does not apply / we never asked". A learner
has no `company_name`, so that column is `NULL`, not `''`.

> **Compared to what you know**
> `NULL` is Python's `None` and JavaScript's `null`. The trap is comparison:
> in SQL, `NULL = NULL` is **not true**. You have to write `IS NULL`. We use
> exactly that in `api/verify-otp.php`: `WHERE used_at IS NULL`.

---

## 2. What do we actually need to remember?

Ask the class, write the answers on the board. You will get something like:

- who they are: name, email, phone, city, password
- what kind of user they are: learner or corporate HR
- learner extras: college, degree, graduation year, tech stack
- HR extras: company, work email, company size, designation
- their three documents
- for the reset feature: OTP codes we sent

Now group them. Things that repeat go into their own table.

```
roles              ← 3 rows, ever
  ↓ (one role has many users)
users              ← one row per person
  ↓ (one user has many documents)
user_documents

users
  ↓ (one user can request many reset codes over time)
password_resets
```

That arrow is the only database design rule you need right now:
**one X has many Y → Y gets a table with an x_id column.**

---

## 3. Why `roles` is its own table

We could have written `role VARCHAR(20)` inside `users` and typed `'learner'`
into every row. Three reasons we did not:

1. **Typos become data.** `'Learner'`, `'learner '`, `'lerner'` are three
   different roles to MySQL, and your `WHERE role = 'learner'` silently
   misses users.
2. **One place to change.** Add `'mentor'` tomorrow → one INSERT, no
   migration of a million rows.
3. **The foreign key enforces it.** With `role_id INT` pointing at `roles.id`,
   an invalid role is not a bug you find in production, it is an error at
   INSERT time.

This is what "normalisation" means in practice: a fact is stored in exactly
one place. You do not need the formal definitions of 1NF/2NF/3NF today; you
need the habit.

---

## 4. Why passwords are not a column of passwords

We will do this properly in lesson 05, but decide it now, because it shapes
the column:

```sql
password      VARCHAR(255)   -- NEVER
password_hash VARCHAR(255)   -- yes
```

If our database leaks — and databases leak — the plain column hands the
attacker every account here *and* every other site where that person reused
the password. The hash column hands them noise. Naming the column
`password_hash` is a small thing that keeps the next developer honest.

255 characters looks generous for something that is 60 characters today.
It is deliberate: `PASSWORD_DEFAULT` is allowed to change algorithm in future
PHP versions and produce longer output.

---

## 5. Why documents are not stored in MySQL

MySQL *can* store a PDF in a `BLOB` column. Almost nobody does.

| | file on disk | blob in MySQL |
|---|---|---|
| `SELECT * FROM users` | stays fast | drags megabytes around |
| Serving the file | Apache does it directly | PHP must read and re-send it |
| Backups | database stays small | every backup is huge |

So: **the bytes go on disk, the facts go in MySQL.**

```
uploads/resume/f7d7b085…pdf          ← the actual file

user_documents
  user_id       = 101
  document_type = 'resume'
  file_path     = 'uploads/resume/f7d7b085….pdf'
  original_name = 'Asha_Resume_Final_v3.pdf'
  file_size     = 184320
  mime_type     = 'application/pdf'
```

Note we keep `original_name` separately. The file on disk gets a random name
(lesson 06 explains why), but the user should still see the name they
recognise.

---

## 6. Write the SQL

Open phpMyAdmin → SQL tab. Type these yourself; do not paste the finished
file yet.

```sql
CREATE DATABASE IF NOT EXISTS nxtwave_portal
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nxtwave_portal;
```

`utf8mb4` is the character set that can store every emoji and every Indian
language script. Plain `utf8` in MySQL is a historical half-version that
cannot. Always `utf8mb4`.

```sql
CREATE TABLE roles (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(50)  NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL
);

INSERT INTO roles (name, label) VALUES
    ('learner',      'Learner'),
    ('corporate_hr', 'Corporate HR'),
    ('employee',     'NxtWave Employee');
```

`UNIQUE` on `name` means MySQL itself refuses a second `'learner'` row.

Now `users`. The full statement is in `sql/schema.sql` — the shape is:

```sql
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    role_id       INT NOT NULL,

    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,   -- the login
    phone         VARCHAR(15)  NOT NULL,
    city          VARCHAR(80)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    college         VARCHAR(150) NULL,            -- learner only
    degree          VARCHAR(100) NULL,
    graduation_year INT          NULL,
    tech_stack      VARCHAR(100) NULL,

    company_name  VARCHAR(150) NULL,              -- HR only
    work_email    VARCHAR(150) NULL,
    company_size  VARCHAR(50)  NULL,
    designation   VARCHAR(100) NULL,

    status     ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
);
```

Two things to point at:

**`email … UNIQUE`.** Later we will also check uniqueness in PHP, twice
(once by AJAX, once on submit). Those checks are for the user's comfort. This
one is the guarantee. Two people clicking submit in the same millisecond will
both pass the PHP check; only one will get past `UNIQUE`.

**`status`.** Learners are `'active'` immediately. Corporate HR accounts are
`'pending'` until a NxtWave employee approves them. This single column is why
our login endpoint in lesson 09 has three answers instead of two.

Then the last two tables (full SQL in `sql/schema.sql`):

```sql
CREATE TABLE user_documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    document_type VARCHAR(50)  NOT NULL,
    file_path     VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size     INT          NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_documents_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

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

`ON DELETE CASCADE`: delete a user and their document rows go too. Without
it, MySQL would block the delete, and you would be left with rows pointing at
a user who no longer exists.

---

### The fifth table

`sql/schema.sql` also creates `login_attempts`, which is not part of any
feature — it exists to slow down someone guessing passwords. Lesson 12
explains it. Ignore it until then; it is in the file now so you only ever
import the schema once.

---

## 7. Drive the tables by hand before PHP touches them

This is the part students skip and then struggle in lesson 04. Run every one
of these in the SQL tab:

```sql
-- CREATE
INSERT INTO users (role_id, full_name, email, phone, city, password_hash, status)
VALUES (1, 'Test User', 'test@example.com', '9876543210', 'Hyderabad', 'not-a-real-hash', 'active');

-- READ
SELECT id, full_name, email FROM users;
SELECT * FROM users WHERE email = 'test@example.com';
SELECT * FROM users WHERE status = 'pending';

-- UPDATE
UPDATE users SET city = 'Pune' WHERE id = 1;

-- DELETE
DELETE FROM users WHERE id = 1;
```

Those four verbs are "CRUD", and they are the whole job. Everything PHP does
to MySQL for the rest of this module is one of these four with a `?` in it.

Now deliberately break things and read the errors:

```sql
INSERT INTO users (role_id, full_name, email, phone, city, password_hash)
VALUES (99, 'Ghost', 'ghost@example.com', '9999999999', 'Nowhere', 'x');
-- Cannot add or update a child row: a foreign key constraint fails
```

```sql
-- run this twice
INSERT INTO users (role_id, full_name, email, phone, city, password_hash)
VALUES (1, 'A', 'same@example.com', '9876543210', 'X', 'x');
-- Duplicate entry 'same@example.com' for key 'email'
```

Good. The database is now defending itself, and you have seen the exact
error message you will get from PHP later.

### One more query to try — the JOIN

`users` stores `role_id = 1`, but we want to display `"Learner"`. The role
name lives in the other table, so we ask for both at once:

```sql
SELECT u.full_name, u.email, r.name AS role_name
FROM   users u
JOIN   roles r ON r.id = u.role_id;
```

`JOIN … ON` means "line up the rows where these two columns match".
`u` and `r` are just short aliases. `AS role_name` renames the output column,
which is how PHP will read it as `$row['role_name']`.

This exact query is the first half of `api/login.php`.

---

## 8. Check yourself

- [ ] All four tables exist, `SHOW TABLES;` proves it.
- [ ] You saw the foreign key error and the duplicate email error yourself.
- [ ] You can explain why `roles` is a table and not a `VARCHAR`.
- [ ] You can explain why a PDF does not go into MySQL.

Now clean up: `DELETE FROM users;` — we want an empty table for lesson 02.

→ [Lesson 02 — PHP basics and the role in the URL](02-php-basics-and-url-role.md)
