<?php
// Every page and endpoint starts with one line:
//     require_once __DIR__ . '/../includes/bootstrap.php';
//
// This is the place for settings that apply to the whole app.

// Set this to false before the site goes live.
// When it is false, PHP errors go to the log instead of the screen. A stack
// trace on a live page hands a stranger your file paths, your database name
// and often a query or two.
const DEBUG = true;

ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Without this, date() and MySQL can disagree about what time it is.
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
