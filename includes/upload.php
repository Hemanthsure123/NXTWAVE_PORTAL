<?php
// Handling the three documents each user uploads.
//
// An uploaded file is input typed by a stranger. The filename, the
// extension and the type the browser reports are all things they control,
// so we check the file itself instead of believing any of it.

const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;   // 2 MB, written so you can read it

// The only three things we accept.
// Key is the real type we detect, value is the extension we save it as.
// The user's own extension is never used.
const ALLOWED_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];

// Check one file. Returns '' when it is fine, or the reason it is not.
// $file is one entry of $_FILES, for example $_FILES['resume'].
function validate_upload(?array $file, string $label): string
{
    if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $label . ' is required.';
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $label . ' is too large.';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $label . ' could not be uploaded. Please try again.';
    }

    // Proves the file really arrived through this request, and is not some
    // path on our own server that got smuggled into the form.
    if (!is_uploaded_file($file['tmp_name'])) {
        return $label . ': invalid upload.';
    }

    if ($file['size'] === 0) {
        return $label . ' is empty.';
    }

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return $label . ' must be 2 MB or smaller.';
    }

    if (!isset(ALLOWED_TYPES[detect_mime_type($file['tmp_name'])])) {
        return $label . ' must be a JPG, PNG or PDF file.';
    }

    return '';
}

// Look at the first bytes on disk to see what the file actually is.
// A text file renamed to photo.png is caught right here.
function detect_mime_type(string $tmpPath): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    return (string) $finfo->file($tmpPath);
}

// Move a checked file into uploads/<folder>/ and return what we want to
// remember about it in MySQL. Returns null if the move failed.
function save_upload(array $file, string $subFolder): ?array
{
    $mime      = detect_mime_type($file['tmp_name']);
    $extension = ALLOWED_TYPES[$mime];

    // A random name means two users cannot overwrite each other, and
    // nothing from the original name survives: no ../, no .php, no spaces.
    $newName = bin2hex(random_bytes(16)) . '.' . $extension;

    $folder = dirname(__DIR__) . '/uploads/' . $subFolder;

    // move_uploaded_file is the only correct way to keep an upload.
    // rename() would not check that it was an upload at all.
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $newName)) {
        return null;
    }

    return [
        // Stored relative to the project, so the path still works if the
        // site moves to another folder or another server.
        'file_path'     => 'uploads/' . $subFolder . '/' . $newName,
        'original_name' => basename($file['name']),
        'file_size'     => (int) $file['size'],
        'mime_type'     => $mime,
    ];
}

// The link to a document, for pages inside public/.
// Not the file's real path - uploads/ is closed to the browser, so
// document.php serves it after checking who is asking.
function document_link(int $documentId): string
{
    return 'document.php?id=' . $documentId;
}

// The document_type column stores 'govt_id'. Screens should show this.
function document_label(string $type): string
{
    return match ($type) {
        'profile_photo'        => 'Profile photo',
        'resume'               => 'Resume / CV',
        'govt_id'              => 'Govt ID',
        'business_id'          => 'Business ID',
        'company_registration' => 'Company registration',
        default                => ucfirst(str_replace('_', ' ', $type)),
    };
}

// "0 KB" is not helpful for a small file.
function format_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    return max(1, (int) round($bytes / 1024)) . ' KB';
}
