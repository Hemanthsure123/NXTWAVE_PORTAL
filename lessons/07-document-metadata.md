# Lesson 07 — Storing document metadata

**Goal:** connect the files on disk to the user in MySQL, and make the
one-to-many relationship from lesson 01 stop being theory.

Short lesson. Big idea: *the disk holds the bytes, the database holds the
meaning.*

---

## 1. What we have after lesson 06

```
uploads/profile/cc9400c217c928126368a510f5b31e77.png
uploads/resume/f7d7b0857163e9ce05d9ef72f3ac5282.pdf
uploads/documents/28ea77057ff165b252f7443687ed9451.png
```

Three files with meaningless names, and nothing anywhere saying who they
belong to or what they are. If you lost this folder's context you could
never rebuild it.

Ask the class what is missing. You want them to arrive at: *whose is it,
what kind of document is it, and what was it called originally.*

---

## 2. Why a second table and not three more columns

The tempting shortcut:

```sql
ALTER TABLE users ADD profile_photo_path VARCHAR(255);
ALTER TABLE users ADD resume_path        VARCHAR(255);
ALTER TABLE users ADD govt_id_path       VARCHAR(255);
```

It works today. It falls apart the first time any of these happens:

- HR users need *different* documents, so now you have six columns and each
  role leaves three of them NULL.
- Someone asks to re-upload a resume and keep the old one. Now what —
  `resume_path_2`?
- You want to know when each file was uploaded. Three more columns.
- Someone asks "how many PDFs are in the system?" You cannot write that
  query against columns; you would have to check three columns per row.

Every one of those is free if documents are **rows**:

```
users                    user_documents
  id = 101   ────────┐     id | user_id | document_type | file_path
                     └──►  1  | 101     | profile_photo | uploads/profile/cc94….png
                           2  | 101     | resume        | uploads/resume/f7d7….pdf
                           3  | 101     | govt_id       | uploads/documents/28ea….png
```

**One user has many documents.** The "many" side gets the table and carries
the `user_id`. That is the rule from lesson 01, now doing real work.

---

## 3. The foreign key, in practice

```sql
CONSTRAINT fk_documents_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
```

Two guarantees you get for free:

1. **You cannot insert a document for a user who does not exist.** Try it in
   phpMyAdmin with `user_id = 99999` and read the error.
2. **`ON DELETE CASCADE`** — deleting a user deletes their document rows.
   Without it MySQL blocks the delete, because removing the user would leave
   rows pointing at nothing (an "orphan").

Note what CASCADE does *not* do: the files on disk stay. Deleting the row
does not delete the PDF. A real system needs a cleanup job, or code that
unlinks the file before deleting the row. Worth saying out loud, because the
gap between "the database is tidy" and "the disk is tidy" surprises people.

---

## 4. The insert loop

From `public/register.php`, inside the transaction:

```php
$userId = (int) $pdo->lastInsertId();

$docStmt = $pdo->prepare(
    'INSERT INTO user_documents
        (user_id, document_type, file_path, original_name, file_size, mime_type)
     VALUES (?, ?, ?, ?, ?, ?)'
);

foreach ($requiredDocuments as $field => $doc) {
    $stored = save_upload($_FILES[$field], $doc['folder']);

    if ($stored === null) {
        throw new RuntimeException('Could not save ' . $doc['label'] . '.');
    }

    $docStmt->execute([
        $userId,
        $field,
        $stored['file_path'],
        $stored['original_name'],
        $stored['file_size'],
        $stored['mime_type'],
    ]);
}
```

Three details worth pausing on:

**`prepare()` once, `execute()` three times.** The statement is parsed by
MySQL a single time and then reused with different values. Preparing inside
the loop would work, and would be three times the work for no reason.

**`$field` is the document type.** The array key that named the form input
(`resume`) is exactly the label we want in the database. One name, three
jobs: the `name` attribute, the `$_FILES` key, the `document_type` value.

**`throw` inside the loop.** We are inside the `try` from lesson 04. A
failure on document two rolls back the user row and document one. No
half-registered accounts.

---

## 5. What we store, and why each column earns its place

```php
return [
    'file_path'     => 'uploads/resume/f7d7….pdf',
    'original_name' => 'Asha_Resume_Final_v3.pdf',
    'file_size'     => 184320,
    'mime_type'     => 'application/pdf',
];
```

- **`file_path`** — relative, never absolute. `C:/xampp/htdocs/...` in the
  database would break the day the project moves to a Linux server.
- **`original_name`** — the file on disk is `f7d7….pdf`, which means nothing
  to a human. We show this instead, and can offer it as the download name.
- **`file_size`** — so a listing can show "180 KB" without touching the disk.
- **`mime_type`** — the type we *detected*, not the one that was claimed. If
  you ever serve these files through PHP, this is the `Content-Type` you
  send.

Notice there is no `uploaded_by_ip` or similar here. Store what the feature
needs. Every extra column about a person is something you now have to
protect.

---

## 6. Reading it back

The dashboards do this:

```php
$stmt = $pdo->prepare('SELECT * FROM user_documents WHERE user_id = ? ORDER BY id');
$stmt->execute([$_SESSION['user_id']]);
$documents = $stmt->fetchAll();
```

`fetchAll()` because we want every row. Then:

```php
<?php foreach ($documents as $doc): ?>
    <tr>
        <td><?= e(str_replace('_', ' ', $doc['document_type'])) ?></td>
        <td>
            <a href="<?= e(document_link($doc['id'])) ?>" target="_blank">
                <?= e($doc['original_name']) ?>
            </a>
        </td>
        <td><?= e(round($doc['file_size'] / 1024)) ?> KB</td>
    </tr>
<?php endforeach; ?>
```

`$_SESSION['user_id']` — not an id from the URL. We will build sessions in
lesson 10, but the principle is already visible: **the user id comes from
the server's own memory, never from the request.** A page that trusts
`?user_id=102` lets anybody read anybody's documents by changing a number.

---

## 7. Serving the file

Notice the link is not the file's path. It cannot be — lesson 06 closed the
uploads folder with `Require all denied`, so `uploads/resume/83866f….pdf`
returns 403 to everybody, including the person who uploaded it.

```php
function document_link(int $documentId): string
{
    return 'document.php?id=' . $documentId;
}
```

So [public/document.php](../public/document.php) is the only way in, and it
decides who gets what. The whole script is about twenty lines:

```php
require_login();

$documentId = (int) get_field('id');

$stmt = $pdo->prepare('SELECT * FROM user_documents WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
    abort(404, 'That document does not exist.');
}

// You may read your own. Staff may read anyone's, because approving a
// company means looking at its registration papers.
$isOwner = (int) $document['user_id'] === $_SESSION['user_id'];
$isStaff = current_role() === 'employee';

if (!$isOwner && !$isStaff) {
    abort(404, 'That document does not exist.');
}
```

Four things in there are worth stopping on.

**The id in the URL is fine here.** Lesson 10 will tell you never to trust
`?id=` — and this page takes one. The difference is what happens next: we
look the row up and then check it against the session. Trusting the id would
mean *using* it without asking whether this visitor may have it.

**A refusal and a missing row give the same 404.** If we returned 403 for
"exists but not yours", anyone could walk `?id=1,2,3…` and count how many
documents the system holds, and which ids are real. Same reasoning as the
login page giving one message for two failures.

**Then the headers:**

```php
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $document['original_name']);

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

readfile($path);
```

`original_name` came from the user, and a quote or a newline inside it would
let them write headers of their own — so it gets scrubbed down to letters,
digits, dots and dashes first. `Content-Disposition: inline` shows the file
in the browser; `attachment` would force a download instead.

`X-Content-Type-Options: nosniff` tells the browser to believe the
`Content-Type` we sent and not go guessing from the bytes. Without it, a
browser that decides a file "looks like" HTML may render it as HTML — and
now a user-uploaded file is running in your site's origin.

**`readfile()`** streams the file straight to the browser. It is the last
statement in the script, because anything echoed after it lands inside the
PDF.

This is the shape of every "download" feature you will ever write: look the
record up, prove the visitor may have it, then send the bytes.

---

## 8. Try it

Register a learner, then run these in phpMyAdmin:

```sql
-- the user and their documents in one result
SELECT u.full_name, d.document_type, d.original_name, d.file_size
FROM   users u
JOIN   user_documents d ON d.user_id = u.id;

-- how many documents does each user have?
SELECT u.email, COUNT(d.id) AS docs
FROM   users u
LEFT   JOIN user_documents d ON d.user_id = u.id
GROUP  BY u.id, u.email;
```

`LEFT JOIN` keeps users who have no documents at all (they show `0`). A
plain `JOIN` would hide them. That difference is worth two minutes.

Then the destructive test, on a throwaway user:

```sql
DELETE FROM users WHERE email = 'test@example.com';
SELECT * FROM user_documents;     -- their rows are gone too
```

And look in `uploads/` — the files are still there. Discuss.

---

## 9. Check yourself

- [ ] Registering one learner creates 1 user row and 3 document rows.
- [ ] You can explain why documents are a table, not three columns.
- [ ] You know what `ON DELETE CASCADE` does, and what it does not.
- [ ] You can write the JOIN that lists a user with their documents.
- [ ] Opening a document link works for the owner, and 404s for anyone else.

Registration now works end to end — but the email uniqueness check only
happens after the user has filled in twenty fields and pressed submit. Let's
fix that without reloading the page.

→ [Lesson 08 — AJAX email check](08-ajax-email-check.md)
