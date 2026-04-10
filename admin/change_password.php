<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin()) redirect(BASE_URL . 'admin/login.php');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old  = $_POST['old_password']  ?? '';
    $new1 = $_POST['new_password']  ?? '';
    $new2 = $_POST['confirm_password'] ?? '';

    $stmt = db()->prepare("SELECT password_hash FROM admins WHERE id=?");
    $stmt->execute([$_SESSION['admin_id']]);
    $row = $stmt->fetch();

    if (!password_verify($old, $row['password_hash'])) {
        $msg = render_error('Current password is incorrect.');
    } elseif ($new1 !== $new2) {
        $msg = render_error('New passwords do not match.');
    } elseif (strlen($new1) < 8) {
        $msg = render_error('New password must be at least 8 characters.');
    } else {
        $hash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare("UPDATE admins SET password_hash=? WHERE id=?")->execute([$hash, $_SESSION['admin_id']]);
        $msg = '<div class="notice-msg">Password changed successfully.</div>';
    }
}
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Change Password</title>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css"></head>
<body>
<h1>Change Password</h1>
<?= $msg ?>
<fieldset>
<form method="post">
<div class="form-row"><label>Current password:</label><input type="password" name="old_password" required></div>
<div class="form-row"><label>New password:</label><input type="password" name="new_password" required minlength="8"></div>
<div class="form-row"><label>Confirm new:</label><input type="password" name="confirm_password" required minlength="8"></div>
<div class="form-row"><input type="submit" value="Change Password"></div>
</form>
</fieldset>
<a href="index.php">← Back</a>
</body></html>
