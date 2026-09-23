<?php
// The top half of every page. Pages set $pageTitle before including it.
//
// Nothing that sends a header - a redirect, session_start(), JSON - can
// run after this file, because the HTML has already started going out.
$pageTitle = $pageTitle ?? 'NxtWave Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | NxtWave Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="../index.php">NxtWave Portal</a>
    <nav>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <span class="who"><?= e($_SESSION['full_name']) ?></span>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
        <?php endif; ?>
    </nav>
</header>
<main class="page">
