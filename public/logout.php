<?php
// Logout. Destroying the session on the server is the part that matters -
// just clearing the cookie would leave a working session behind.

require_once __DIR__ . '/../includes/bootstrap.php';

logout_user();

redirect('login.php');
