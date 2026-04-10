<?php
require_once __DIR__ . '/../includes/core.php';

if (is_admin()) redirect(BASE_URL . 'admin/');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    $stmt = db()->prepare("SELECT * FROM admins WHERE username=? LIMIT 1");
    $stmt->execute([$user]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($pass, $admin['password_hash'])) {
        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_name'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        redirect(BASE_URL . 'admin/');
    } else {
        $error = render_error('Invalid credentials.');
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Login</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
</head>
<body>
<h1>Admin Login</h1>
<?= $error ?>
<fieldset>
<form method="post">
<div class="form-row"><label>Username:</label>
<input type="text" name="username" required></div>
<div class="form-row"><label>Password:</label>
<input type="password" name="password" required></div>
<div class="form-row"><input type="submit" value="Login"></div>
</form>
</fieldset>
</body>
</html>
