<?php
// Landing page. Kept standalone because its asset paths differ from the
// pages inside public/.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NxtWave Portal</title>
    <link rel="stylesheet" href="public/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <span class="brand">NxtWave Portal</span>
    <nav><a href="public/login.php">Login</a></nav>
</header>

<main class="page">
    <div class="intro">
        <h1>Create your account</h1>
        <p>Choose the option that describes you.</p>
    </div>

    <div class="choices">
        <a class="choice" href="public/register.php?type=learner">
            <h2>I am a learner</h2>
            <p>Join a NxtWave program and track your application.</p>
            <span class="choice-action">Register as a learner</span>
        </a>

        <a class="choice" href="public/register.php?type=corporate_hr">
            <h2>I am hiring</h2>
            <p>Find and recruit talent trained at NxtWave.</p>
            <span class="choice-action">Register as corporate HR</span>
        </a>
    </div>

    <p class="footnote">
        Already registered? <a href="public/login.php">Log in</a>
    </p>
</main>

<footer class="site-footer">NxtWave Portal</footer>
</body>
</html>
