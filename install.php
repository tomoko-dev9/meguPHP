<?php
/**
 * install.php — Run once to set up admin account, then DELETE THIS FILE.
 * Access via browser: http://yoursite/imageboard/install.php
 */
require_once __DIR__ . '/config.php';

$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $pass2 = trim($_POST['password2'] ?? '');

    if (!$user || strlen($user) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Check if already installed
            $existing = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            if ($existing > 0) {
                $error = 'Already installed. Delete install.php.';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?,?,'admin')")
                    ->execute([$user, $hash]);
                $done = true;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Imageboard Installer</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 60px auto; background: #eef2ff; }
        fieldset { background: #d6daf0; border: 1px solid #b7c5d9; padding: 16px; }
        label { display: block; margin-bottom: 4px; font-weight: bold; }
        input[type=text], input[type=password] { width: 100%; padding: 4px; margin-bottom: 12px; }
        input[type=submit] { background: #d6daf0; border: 1px solid #b7c5d9; padding: 6px 16px; cursor: pointer; font-weight: bold; }
        .error { background: #f0d6d6; border: 1px solid #d8a8a8; padding: 8px; margin-bottom: 12px; color: #900; }
        .success { background: #d6f0da; border: 1px solid #a8d8b0; padding: 8px; color: #117743; }
        h1 { color: #af0a0f; font: bolder 28px Tahoma; letter-spacing: -2px; }
    </style>
</head>
<body>
<h1>Imageboard Installer</h1>

<?php if ($done): ?>
<div class="success">
    <strong>Installation complete!</strong><br>
    Admin account created. <strong>Delete install.php now!</strong><br>
    <a href="<?= BASE_URL ?>admin/">Go to admin panel</a>
</div>
<?php else: ?>

<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<p>Make sure you have run <code>schema.sql</code> against your MySQL database first.</p>

<fieldset>
<legend>Create Admin Account</legend>
<form method="post">
<label>Admin Username:</label>
<input type="text" name="username" required minlength="3" maxlength="50">
<label>Password:</label>
<input type="password" name="password" required minlength="8">
<label>Confirm Password:</label>
<input type="password" name="password2" required minlength="8">
<input type="submit" value="Install">
</form>
</fieldset>

<?php endif; ?>
</body>
</html>
