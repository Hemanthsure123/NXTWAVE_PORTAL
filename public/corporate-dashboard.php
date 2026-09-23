<?php
// Corporate HR dashboard.
// Same shape as the learner one, different guard and different columns.
// A learner cannot open this page and an HR user cannot open theirs, even
// by typing the URL.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload.php';

require_role('corporate_hr');

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM user_documents WHERE user_id = ? ORDER BY id');
$stmt->execute([$_SESSION['user_id']]);
$documents = $stmt->fetchAll();

$pageTitle = 'Corporate HR dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h1>Hello, <?= e($user['full_name']) ?></h1>
    <p class="subtitle">Corporate HR account &middot; joined <?= e(date('d M Y', strtotime($user['created_at']))) ?></p>

    <table>
        <tr><th>Login email</th><td><?= e($user['email']) ?></td></tr>
        <tr><th>Work email</th><td><?= e($user['work_email']) ?></td></tr>
        <tr><th>Phone</th><td><?= e($user['phone']) ?></td></tr>
        <tr><th>City</th><td><?= e($user['city']) ?></td></tr>
        <tr><th>Company</th><td><?= e($user['company_name']) ?></td></tr>
        <tr><th>Company size</th><td><?= e($user['company_size']) ?> employees</td></tr>
        <tr><th>Designation</th><td><?= e($user['designation']) ?></td></tr>
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
