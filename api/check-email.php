<?php
// GET api/check-email.php?email=someone@example.com
//
// Answers one question: is this email free? No HTML anywhere, just JSON.
// That is the whole difference between a page and an endpoint.
//
//   {"available": true,  "message": "Email is available"}
//   {"available": false, "message": "Email already registered"}

require_once __DIR__ . '/../includes/bootstrap.php';

$email = strtolower(get_field('email'));

// An empty box is not an error, there is just nothing to check yet.
if ($email === '') {
    json_response(['available' => false, 'message' => '']);
}

if (!is_valid_email($email)) {
    json_response(['available' => false, 'message' => 'That does not look like an email address']);
}

// Same query register.php runs on submit. The ? never becomes part of the
// SQL, so typing  ' OR 1=1 --  into the box does nothing interesting.
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);

if ($stmt->fetch()) {
    json_response(['available' => false, 'message' => 'Email already registered']);
}

json_response(['available' => true, 'message' => 'Email is available']);
