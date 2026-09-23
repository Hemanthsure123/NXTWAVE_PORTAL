<?php
// Small helpers used all over the portal.

// Escape text before printing it in HTML.
// Without this, a user named <script>alert(1)</script> gets their script
// run by everyone who opens the page.
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Read one form field, already trimmed. The ?? '' is there because the
// key may not exist at all.
function post_field(string $key): string
{
    return trim($_POST[$key] ?? '');
}

// Read one value from the URL: register.php?type=learner
function get_field(string $key): string
{
    return trim($_GET[$key] ?? '');
}

// Stop the request with a short error page. Used by the page guards and
// by anything that cannot continue.
function abort(int $status, string $message): never
{
    http_response_code($status);

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . $status . '</title></head><body '
        . 'style="font-family:Arial,sans-serif;margin:80px auto;max-width:420px;color:#23272f">'
        . '<h1 style="font-size:20px">' . $status . '</h1>'
        . '<p>' . e($message) . '</p>'
        . '<p><a href="/nxtwave-portal/">Back to the portal</a></p>'
        . '</body></html>';

    exit;
}

// Send JSON and stop. Everything in api/ ends with one of these.
// The Content-Type header is what tells JavaScript it is looking at JSON.
function json_response(array $data, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Go to another page. Always exit after a redirect, or the rest of the
// page keeps running and gets sent too.
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// 10 digits starting with 6-9. preg_match gives 1 when it matches.
function is_valid_phone(string $phone): bool
{
    return preg_match('/^[6-9][0-9]{9}$/', $phone) === 1;
}

// Put the user's value back in the box after a failed submit.
// A form that wipes itself is a form people give up on.
function old(string $key): string
{
    return e($_POST[$key] ?? '');
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

// $_POST is empty when JavaScript sends JSON, so we read the body ourselves.
// json_decode's second argument true means "give me an array".
function json_input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);

    return is_array($data) ? $data : [];
}
