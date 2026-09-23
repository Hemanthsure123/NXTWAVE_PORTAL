<?php
// Serves one uploaded document, to someone allowed to see it.
//
//     document.php?id=12
//
// The uploads folder itself is closed off in uploads/.htaccess, so this is
// the only way in and the permission check cannot be skipped.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload.php';

require_login();

$documentId = (int) get_field('id');

$stmt = $pdo->prepare('SELECT * FROM user_documents WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
    abort(404, 'That document does not exist.');
}

// You may read your own documents. Staff may read anyone's, because
// approving a company means looking at its registration papers.
$isOwner = (int) $document['user_id'] === $_SESSION['user_id'];
$isStaff = current_role() === 'employee';

if (!$isOwner && !$isStaff) {
    // Same answer as a missing document, so nobody can count our rows by
    // trying ids and watching which ones say "not allowed".
    abort(404, 'That document does not exist.');
}

$path = dirname(__DIR__) . '/' . $document['file_path'];

if (!is_file($path)) {
    error_log('Missing file on disk for document ' . $documentId);
    abort(404, 'That document is no longer available.');
}

// Only ever send back one of the three types we accept. If a row somehow
// holds anything else, we are not going to be the ones to serve it.
if (!isset(ALLOWED_TYPES[$document['mime_type']])) {
    abort(404, 'That document is no longer available.');
}

// The original filename came from the user, and a quote or a newline in it
// would let them write their own headers. Keep letters, digits, dot, dash.
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $document['original_name']);

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $filename . '"');

// Stops the browser second-guessing the type we just declared.
header('X-Content-Type-Options: nosniff');

readfile($path);
