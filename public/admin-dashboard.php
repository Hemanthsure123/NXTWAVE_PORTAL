<?php
// NxtWave employee dashboard.
//
// Corporate HR accounts are created with status 'pending'. Until somebody
// approves one here, api/login.php answers that account with
// account_pending_review.

require_once __DIR__ . '/../includes/bootstrap.php';

require_role('employee');

$notice = '';

if (is_post()) {
    // Check the token before anything else. A request without it did not
    // come from one of our forms.
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Bad request token.');
    }

    $targetId = (int) ($_POST['user_id'] ?? 0);
    $decision = post_field('decision');

    if ($targetId > 0 && in_array($decision, ['active', 'rejected'], true)) {
        // AND status = 'pending' lives in the query rather than in an if
        // above it, so it still holds when two people click at once.
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$decision, $targetId, 'pending']);

        $notice = $stmt->rowCount() > 0
            ? 'Account #' . $targetId . ' marked as ' . $decision . '.'
            : 'Nothing changed - that account was not pending.';
    }
}

// query() instead of prepare() is fine here: there is no user input
// anywhere in these two strings.
$pending = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.company_name, u.designation, u.created_at
     FROM   users u
     JOIN   roles r ON r.id = u.role_id
     WHERE  u.status = 'pending' AND r.name = 'corporate_hr'
     ORDER  BY u.created_at"
)->fetchAll();

// LEFT JOIN keeps roles that have no users yet, showing 0 instead of
// hiding the row.
$counts = $pdo->query(
    'SELECT r.label, COUNT(u.id) AS total
     FROM   roles r
     LEFT   JOIN users u ON u.role_id = r.id
     GROUP  BY r.id, r.label'
)->fetchAll();

$pageTitle = 'Employee dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h1>Employee dashboard</h1>
    <p class="subtitle">Signed in as <?= e($_SESSION['full_name']) ?>.</p>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-info"><?= e($notice) ?></div>
    <?php endif; ?>

    <table>
        <tr><th>Role</th><th>Accounts</th></tr>
        <?php foreach ($counts as $row): ?>
            <tr><td><?= e($row['label']) ?></td><td><?= e($row['total']) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Corporate HR accounts waiting for review</h2>

    <?php if (!$pending): ?>
        <p class="empty">Nothing pending right now.</p>
    <?php else: ?>
        <table>
            <tr><th>Name</th><th>Company</th><th>Email</th><th>Requested</th><th></th></tr>
            <?php foreach ($pending as $row): ?>
                <tr>
                    <td><?= e($row['full_name']) ?><br><small><?= e($row['designation']) ?></small></td>
                    <td><?= e($row['company_name']) ?></td>
                    <td><?= e($row['email']) ?></td>
                    <td><?= e(date('d M Y', strtotime($row['created_at']))) ?></td>
                    <td>
                        <form method="post" class="actions">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="user_id" value="<?= e($row['id']) ?>">
                            <button type="submit" name="decision" value="active">Approve</button>
                            <button type="submit" name="decision" value="rejected" class="danger">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
