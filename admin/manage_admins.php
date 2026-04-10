<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin() || admin_role() !== 'admin') redirect(BASE_URL . 'admin/');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['admin','moderator']) ? $_POST['role'] : 'moderator';
        if (!$username || strlen($password) < 8) {
            $msg = render_error('Username required and password must be 8+ chars.');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?,?,?)")
                    ->execute([$username, $hash, $role]);
                $msg = '<div class="notice-msg">Account created.</div>';
            } catch (Exception $e) {
                $msg = render_error('Username already exists.');
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $del_id = (int)($_POST['del_id'] ?? 0);
        if ($del_id && $del_id !== (int)$_SESSION['admin_id']) {
            db()->prepare("DELETE FROM admins WHERE id=?")->execute([$del_id]);
            $msg = '<div class="notice-msg">Account deleted.</div>';
        } else {
            $msg = render_error('Cannot delete your own account.');
        }
    }
}

$admins = db()->query("SELECT id, username, role, created_at FROM admins ORDER BY id")->fetchAll();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Manage Admins</title>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css"></head>
<body>
<h1>Manage Admins</h1>
<?= $msg ?>

<h2>Existing Accounts</h2>
<table>
<tr><th>ID</th><th>Username</th><th>Role</th><th>Created</th><th>Actions</th></tr>
<?php foreach ($admins as $a): ?>
<tr>
    <td><?= $a['id'] ?></td>
    <td><?= h($a['username']) ?></td>
    <td><?= h($a['role']) ?></td>
    <td><?= $a['created_at'] ?></td>
    <td>
        <?php if ($a['id'] !== (int)$_SESSION['admin_id']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this account?')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="del_id" value="<?= $a['id'] ?>">
        <input type="submit" value="Delete">
        </form>
        <?php else: ?><em>(you)</em><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>

<h2>Add Account</h2>
<fieldset>
<form method="post">
<input type="hidden" name="action" value="add">
<div class="form-row"><label>Username:</label><input type="text" name="username" required maxlength="50"></div>
<div class="form-row"><label>Password:</label><input type="password" name="password" required minlength="8"></div>
<div class="form-row"><label>Role:</label>
<select name="role">
<option value="moderator">Moderator</option>
<option value="admin">Admin</option>
</select></div>
<div class="form-row"><input type="submit" value="Create Account"></div>
</form>
</fieldset>

<a href="index.php">← Back</a>
</body></html>
