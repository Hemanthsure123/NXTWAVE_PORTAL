# Lesson 05 — Password hashing

**Goal:** stop storing passwords. Store something that proves a password
without being one.

This is a short lesson with a large idea in it.

---

## 1. Start with the failure

Open phpMyAdmin and look at the `users` row you created in lesson 04.

```
password_hash: secret12345
```

Now ask the class three questions:

1. Every developer at this company can read that column. Is that fine?
2. A backup of this database sits on a laptop. That laptop is stolen. Now?
3. That person uses `secret12345` on their bank, their email and Instagram.
   How many accounts did we just lose for them?

The third one is the real damage. People reuse passwords, and the breach of
a small student portal becomes a breach of everything else they own. Storing
a plain password is not a bug in your app — it is a liability you are
holding on behalf of other companies.

---

## 2. Encryption is not the answer

The instinctive fix is "encrypt it". That is the wrong tool, and it is worth
being precise about why.

```
Encryption  is TWO-way.  encrypt("secret") -> "8f2a..."  and back again
                          with the key, anyone can recover "secret"

Hashing     is ONE-way.  hash("secret") -> "$2y$10$k3..."
                          there is no un-hash function. There is no key.
```

If the passwords are encrypted, the key has to live on the same server that
does the logging in. Steal the database, steal the key, and you have the
passwords. Encryption is right for something you need to read back — an API
token, a stored document. A password is something you never need to read
back. You only ever need to answer: *is this the same one?*

So we hash.

```
Registration:  hash the password        -> store the hash
Login:         hash what they typed     -> compare with the stored hash
```

The server itself never knows the password after the request ends. That is
the point.

### Not just any hash

`md5()` and `sha1()` exist in PHP and you will see them in old tutorials.
Do not use them for passwords. They were designed to be *fast* — a modern
GPU computes billions of MD5 hashes a second, so an attacker with your
table simply tries every common password until the hashes match.

A password hash needs to be deliberately **slow**, and it needs a **salt**:
random data mixed into each hash, so that two users with the same password
get different hashes and one precomputed table cannot crack them both.

You do not have to implement any of that.

---

## 3. `password_hash()`

```php
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
```

That single line picks a strong algorithm (bcrypt today), generates a random
salt, and runs the slow hashing. Look at what comes out:

```
$2y$10$N9qo8uLOickgx2ZMRZoMye.IjRTQQ0sJ3KZPGVn7v.BOMY5NnU7kq
 │  │  └── salt + hash
 │  └───── cost: 2^10 rounds
 └──────── algorithm id: 2y = bcrypt
```

Everything needed to verify it later is inside the string, which is why the
column is a plain `VARCHAR(255)` and there is no separate salt column.

Run this twice with the same password:

```php
echo password_hash('secret12345', PASSWORD_DEFAULT), "\n";
echo password_hash('secret12345', PASSWORD_DEFAULT), "\n";
```

Two different outputs. That is the salt working. And it immediately tells
you how login must work — you cannot hash the input and compare strings.

> **Compared to what you know**
> Python: `werkzeug.security.generate_password_hash()` or `bcrypt.hashpw()`.
> Node: `bcrypt.hash()`. Same idea, same reasoning, in every stack.

`PASSWORD_DEFAULT` rather than `PASSWORD_BCRYPT` is intentional: when PHP
decides a better algorithm is the default, your new hashes upgrade for free.
That is also why the column is 255 wide and not 60.

---

## 4. `password_verify()`

We will use this properly in lesson 09, but see it now so the pair makes
sense:

```php
if (password_verify($typedPassword, $user['password_hash'])) {
    // correct
}
```

`password_verify` reads the algorithm, cost and salt out of the stored
string, hashes the typed password the same way, and compares the results —
in constant time, so an attacker cannot learn anything from how long it took.

**Never** write this:

```php
if (password_hash($typed, PASSWORD_DEFAULT) === $user['password_hash']) {  // always false
```

Because of the random salt, it will never match, not even for the right
password. Every class has one person who tries it; better it is deliberate.

---

## 5. Put it in the project

In `public/register.php`, just before the INSERT:

```php
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
```

and in the bound parameters:

```php
':password_hash' => $passwordHash,
```

`$password` itself is never written anywhere. Not to the database, not to a
log file, not to an error message. When the request ends, it is gone.

Register a new user and look at the row:

```
password_hash: $2y$10$3kQm1u.0yYxKk8Qm8V0Dee1Kg...
```

That column is now something you could paste into a group chat without
having handed anyone an account.

---

## 6. Where hashing is not enough

Worth mentioning out loud, because students often think hashing solves
"password security" entirely. It solves exactly one problem: what an
attacker gets when they read your table. It does nothing about:

- weak passwords (`password1` hashes beautifully and is still guessable)
- someone typing 10,000 login attempts — that needs rate limiting
- a password sent over plain HTTP — that needs HTTPS

We handle attempts and expiry for OTPs in lesson 11, and the rest is the
checklist in lesson 12.

---

## 7. Check yourself

- [ ] You can explain hashing vs encryption in one sentence each.
- [ ] You know why the same password produces two different hashes.
- [ ] You know why `md5()` is wrong here.
- [ ] Your `users` table contains no readable password anywhere.

→ [Lesson 06 — File uploads](06-file-uploads.md)
