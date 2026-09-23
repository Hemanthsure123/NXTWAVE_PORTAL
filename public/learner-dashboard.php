<?php
// Learner dashboard.
// The guard is the first thing that runs. Everything below it can assume
// a logged in learner is looking at the page.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload.php';

require_role('learner');

// The id comes from the session, never from the URL. A page that trusts
// ?id=102 lets anyone read anyone else's documents by changing a number.
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM user_documents WHERE user_id = ? ORDER BY id');
$stmt->execute([$_SESSION['user_id']]);
$documents = $stmt->fetchAll();

$pageTitle = 'Learner dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h1>Hello, <?= e($user['full_name']) ?></h1>
    <p class="subtitle">Learner account &middot; joined <?= e(date('d M Y', strtotime($user['created_at']))) ?></p>

    <table>
        <tr><th>Email</th><td><?= e($user['email']) ?></td></tr>
        <tr><th>Phone</th><td><?= e($user['phone']) ?></td></tr>
        <tr><th>City</th><td><?= e($user['city']) ?></td></tr>
        <tr><th>College</th><td><?= e($user['college']) ?></td></tr>
        <tr><th>Degree</th><td><?= e($user['degree']) ?></td></tr>
        <tr><th>Graduation year</th><td><?= e($user['graduation_year']) ?></td></tr>
        <tr><th>Target tech stack</th><td><?= e($user['tech_stack']) ?></td></tr>
    </table>
</div>

<div class="card">
    <h2>Your documents</h2>

    <table>
        <tr><th>Document</th><th>File</th><th>Size</th><th>Uploaded</th></tr>
        <?php foreach ($documents as $doc): ?>
            <tr>
                <td><?= e(document_label($doc['document_type'])) ?></td>
                <td>
                    <a href="<?= e(document_link($doc['id'])) ?>" target="_blank">
                        <?= e($doc['original_name']) ?>
                    </a>
                </td>
                <td><?= e(format_size($doc['file_size'])) ?></td>
                <td><?= e(date('d M Y', strtotime($doc['uploaded_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
