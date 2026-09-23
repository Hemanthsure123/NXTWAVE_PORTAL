# Lesson 06 — File uploads

**Goal:** accept three documents per user — max 2 MB, JPG / PNG / PDF only —
and treat every one of them as hostile until proven otherwise.

This is the most security-heavy lesson in the module. A file upload is the
one place where a stranger gets to put *their* bytes on *your* disk.

---

## 1. Start with the question

> "The user picked `resume.pdf` and hit submit. Where is that file right
> now?"

Let them guess. Then find out. Add a file input:

```html
<form method="post" enctype="multipart/form-data">
    <input type="file" name="resume">
    <button>Upload</button>
</form>
```

and at the top of the PHP:

```php
if (is_post()) {
    echo '<pre>';
    print_r($_FILES);
    echo '</pre>';
}
```

Submit a PDF and you get:

```
Array
(
    [resume] => Array
        (
            [name] => Asha_Resume_Final_v3.pdf
            [full_path] => Asha_Resume_Final_v3.pdf
            [type] => application/pdf
            [tmp_name] => C:\xampp\tmp\php9A2B.tmp
            [error] => 0
            [size] => 184320
        )
)
```

So: PHP has already received the whole file and written it to a temporary
folder. Read the five keys carefully, because who controls each one is the
entire lesson.

| key | what it is | who decides it |
|---|---|---|
| `name` | the original filename | **the user** |
| `type` | the claimed MIME type | **the user's browser** |
| `tmp_name` | where PHP parked it | PHP |
| `size` | bytes actually received | PHP |
| `error` | 0 = fine | PHP |

Two of those five are attacker-controlled. `name` and `type` are *claims*,
not facts.

And one more thing about `tmp_name`: that temporary file is deleted the
moment the script ends. If you do not move it during this request, it is
gone. That is not a bug, it is cleanup.

---

## 2. `enctype`, again

Remove `enctype="multipart/form-data"` and resubmit. `$_FILES` is an empty
array. No warning, no error.

The reason: a normal form is sent as `key=value&key=value`, which cannot
carry binary data. `multipart/form-data` is a different body format that
wraps each field, and each file, in its own labelled part. Without it the
browser sends only the filename as text.

When a student says "my upload isn't working", this is the first thing to
check, every time.

---

## 3. Check `error` before anything else

```php
$file = $_FILES['resume'] ?? null;

if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
    $errors[] = 'Resume is required.';
}
```

The constants you will actually meet:

| constant | value | meaning |
|---|---|---|
| `UPLOAD_ERR_OK` | 0 | fine |
| `UPLOAD_ERR_INI_SIZE` | 1 | bigger than `upload_max_filesize` in php.ini |
| `UPLOAD_ERR_FORM_SIZE` | 2 | bigger than the form's `MAX_FILE_SIZE` |
| `UPLOAD_ERR_PARTIAL` | 3 | connection dropped mid-upload |
| `UPLOAD_ERR_NO_FILE` | 4 | they did not choose one |

`validate_upload()` in [includes/upload.php](../includes/upload.php) turns
these into English. It only spells out the two size errors and treats the
rest as "please try again" — the user cannot act on "error code 3" anyway,
and you have the real number in the logs.

Worth knowing: PHP has its own limits, set in `php.ini`, and they sit
*above* our 2 MB rule. Check yours:

```php
echo ini_get('upload_max_filesize');   // 40M on this XAMPP install
echo ini_get('post_max_size');         // 40M
```

They matter because they fail differently. Go over `upload_max_filesize` and
you get `UPLOAD_ERR_INI_SIZE`, which our code reports properly. Go over
`post_max_size` and PHP throws away the **entire request body** — `$_FILES`
*and* `$_POST` both arrive empty, with no error code to read, so the form
looks like it was submitted blank. That confuses everyone once.

Older XAMPP builds shipped with 2M and 8M, which is small enough that
students hit it by accident. If your numbers are low, raise them in
`C:\xampp\php\php.ini` and restart Apache.

---

## 4. The four checks

### a) Is it really an upload?

```php
if (!is_uploaded_file($file['tmp_name'])) {
    return $label . ': invalid upload.';
}
```

This asks PHP: "did *this* request actually upload this path?" It closes a
class of attacks where a crafted request tries to make your script operate on
a file that was already on your server — `C:\xampp\php\php.ini`, say.

### b) Size

```php
const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;      // 2 MB, spelled out

if ($file['size'] > MAX_UPLOAD_BYTES) {
    return $label . ' must be 2 MB or smaller.';
}
```

`2 * 1024 * 1024` instead of `2097152` because one of those is readable at a
glance in six months.

`const` at file scope defines a constant — no `$`, cannot be reassigned.

> Python has no real constants, just the SCREAMING_CASE convention.
> JavaScript's `const` is per-scope. PHP's `const` is global to the file and
> anything that includes it.

### c) Type — and this is the interesting one

The naive check:

```php
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);   // "pdf"
if (in_array($ext, ['jpg', 'png', 'pdf'])) { ... }    // NOT ENOUGH
```

The extension is part of a name the user typed. `shell.php` renamed to
`shell.png` passes this check in full.

The second naive check:

```php
if ($file['type'] === 'application/pdf') { ... }      // ALSO NOT ENOUGH
```

`$_FILES['x']['type']` is copied from a header the *browser* sent. Anyone
using curl sets it to whatever they like:

```bash
curl -F "resume=@shell.php;type=application/pdf" http://localhost/...
```

So we look at the file itself:

```php
function detect_mime_type(string $tmpPath): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return (string) $finfo->file($tmpPath);
}
```

`finfo` reads the first bytes on disk and identifies the format from its
signature — PNG files start with `\x89PNG`, PDFs start with `%PDF`. That is
a fact about the bytes, not a claim about them.

> **Demonstrate this.** Make a text file, rename it `photo.png`, upload it.
> Extension check: passes. Browser `type`: says `image/png`. `finfo`: says
> `text/plain`. Rejected. That demo is the lesson.

Our allow list does double duty:

```php
const ALLOWED_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];
```

Key = the real type we accept. Value = the extension **we** will save it as.
The user's extension is not used at all.

### d) Not empty

```php
if ($file['size'] === 0) {
    return $label . ' is empty.';
}
```

A zero-byte "resume" is not a resume.

---

## 5. Where the file goes, and what it is called

```php
$newName = bin2hex(random_bytes(16)) . '.' . $extension;
// cc9400c217c928126368a510f5b31e77.png
```

`random_bytes(16)` gives 16 cryptographically random bytes; `bin2hex` turns
them into 32 hex characters.

Four problems solved by one line:

1. **Collisions.** Two users both upload `resume.pdf`. Without renaming, the
   second overwrites the first.
2. **Path traversal.** A crafted filename like `../../config/database.php`
   would write outside the uploads folder.
3. **Dangerous extensions.** `invoice.pdf.php` keeps only our extension.
4. **Guessing.** Nobody can browse `uploads/resume/Asha_Resume.pdf` by
   working out names. On its own that is only obscurity, not access control -
   section 7 is what actually locks the folder.

Then move it:

```php
if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    return null;
}
```

Use `move_uploaded_file()`, not `rename()` or `copy()`. It re-checks that
the source really was an upload from this request, and it clears the temp
file. Two safety checks for the same effort.

### Filesystem path vs URL path

These are different things and mixing them up is a rite of passage:

```php
$absolutePath = 'C:/xampp/htdocs/nxtwave-portal/uploads/resume/cc94….pdf';  // for PHP
$storedPath   = 'uploads/resume/cc94….pdf';                                  // for the DB
$linkHref     = '../uploads/resume/cc94….pdf';                               // for the browser
```

We store the **relative** path in MySQL. If the project moves to another
folder or another server, the rows are still correct. Nothing links to that
path directly though - see lesson 07 for why, and what serves the file
instead.

---

## 6. Validate everything, then move anything

Look at the order in `register.php`:

```php
// 3e - validate all three
foreach ($requiredDocuments as $field => $doc) {
    $problem = validate_upload($_FILES[$field] ?? null, $doc['label']);
    if ($problem !== '') {
        $errors[] = $problem;
    }
}

// 3f - only now, inside the transaction, move them
if (!$errors) {
    ...
    $stored = save_upload($_FILES[$field], $doc['folder']);
}
```

If the third file is invalid, the first two were never written. Otherwise a
failed registration leaves orphan files on disk that nothing will ever clean
up.

Note also that the three documents are driven by an array, not by copy-paste:

```php
$requiredDocuments = [
    'profile_photo' => ['label' => 'Profile Photo',        'folder' => 'profile'],
    'resume'        => ['label' => 'Resume / CV',          'folder' => 'resume'],
    'govt_id'       => ['label' => 'Govt ID / Student ID', 'folder' => 'documents'],
];
```

One array drives the form inputs, the validation loop and the save loop.
Swapping in the corporate HR set is a different array, not different code.

---

## 7. Shut the uploads folder completely

Two separate problems live in this folder.

**The first is execution.** Imagine an attacker gets a file containing
`<?php system($_GET['c']); ?>` into `uploads/`, named `x.php`. If Apache is
willing to run PHP there, they have a shell on your machine, from a browser.

**The second is reading.** These are government IDs and resumes. Before we
did anything, `uploads/resume/83866f….pdf` was a URL anybody could open —
no login, no check. The random filename makes it hard to guess, but *hard to
guess* is not *not allowed*. A filename is not a password: it leaks through
browser history, the `Referer` header, a shared link, a server log.

Both problems have the same answer. `uploads/.htaccess`:

```apache
Require all denied
```

One line, and nothing in that folder is reachable from the web at all — not
as a program, not as a file.

Which raises the obvious question: how does a learner see their own resume?
That is lesson 07. The short version is that a PHP script reads the file and
hands it over, after checking who is asking.

**Defence in depth:** assume each layer will eventually fail, and make sure
it is not the only one. Even with `Require all denied` we still validate the
type, still rename the file, still cap the size. Any one of those could have
a bug; all four having the same bug is unlikely.

A step further, for a real deployment: put the uploads folder somewhere
completely outside the web root, so that a misconfigured server cannot
expose it even by accident. Our folder sits inside the project because that
keeps the XAMPP setup to one copy-paste.

---

## 8. Try to break it

Work through these as a class:

| attempt | expected |
|---|---|
| a 3 MB PDF | rejected on size |
| a `.docx` | rejected on type |
| a text file renamed `photo.png` | rejected — `finfo` sees `text/plain` |
| submit with no file chosen | "… is required" |
| a valid PNG named `../../evil.png` | saved as a random hex name in the right folder |
| two users uploading `resume.pdf` | two different files on disk |
| pasting an upload URL into the browser | 403 from Apache |

And with curl, to show that the browser was never the gatekeeper:

```bash
curl -X POST "http://localhost/nxtwave-portal/public/register.php?type=learner" \
     -F "resume=@notes.txt;type=application/pdf"
```

The lie in `;type=application/pdf` is believed by nobody.

---

## 9. Check yourself

- [ ] You can name the five keys of a `$_FILES` entry and say who controls each.
- [ ] You can explain why extension checking alone is useless.
- [ ] You know why `move_uploaded_file()` beats `rename()`.
- [ ] You know why the saved filename is random.
- [ ] Files land in `uploads/profile`, `uploads/resume`, `uploads/documents`.
- [ ] Pasting an upload URL straight into the browser gives you 403.

The files are on disk. MySQL still knows nothing about them.

→ [Lesson 07 — Storing document metadata](07-document-metadata.md)
