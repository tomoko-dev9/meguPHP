<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin() || admin_role() !== 'admin') redirect(BASE_URL . 'admin/');

$msg = '';

// Add ban
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $ip      = trim($_POST['ip'] ?? '');
    $board   = trim($_POST['board_uri'] ?? '') ?: null;
    $reason  = trim($_POST['reason'] ?? '');
    $expires = trim($_POST['expires_at'] ?? '') ?: null;

    if (!$ip) {
        $msg = render_error('IP address is required.');
    } else {
        db()->prepare("INSERT INTO bans (ip, board_uri, reason, expires_at, banned_by) VALUES (?,?,?,?,?)")
            ->execute([$ip, $board, $reason, $expires, $_SESSION['admin_name']]);
        $msg = '<div class="notice-msg">Ban added.</div>';
    }
}

// Remove ban
if (isset($_GET['unban'])) {
    db()->prepare("DELETE FROM bans WHERE id=?")->execute([(int)$_GET['unban']]);
    redirect(BASE_URL . 'admin/bans.php');
}

$bans = db()->query("SELECT * FROM bans ORDER BY created_at DESC LIMIT 100")->fetchAll();
$boards = get_all_boards();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Bans</title>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css"></head>
<body>
<h1>Bans</h1>
<?= $msg ?>

<fieldset>
<legend>Add Ban</legend>
<form method="post">
<input type="hidden" name="action" value="add">
<div class="form-row"><label>IP:</label><input type="text" name="ip" required maxlength="45"></div>
<div class="form-row"><label>Board:</label>
<select name="board_uri">
<option value="">Global</option>
<?php foreach ($boards as $b): ?>
<option value="<?= h($b['uri']) ?>">/<?= h($b['uri']) ?>/</option>
<?php endforeach; ?>
</select></div>
<div class="form-row"><label>Reason:</label><input type="text" name="reason" maxlength="255"></div>
<div class="form-row"><label>Expires:</label><input type="datetime-local" name="expires_at"> (leave blank = permanent)</div>
<div class="form-row"><input type="submit" value="Add Ban"></div>
</form>
</fieldset>

<h2>Active Bans</h2>
<?php if (!$bans): ?>
<p>No bans.</p>
<?php else: ?>
<table>
<tr><th>IP</th><th>Board</th><th>Reason</th><th>Expires</th><th>By</th><th>Actions</th></tr>
<?php foreach ($bans as $ban): ?>
<tr>
    <td><?= h($ban['ip']) ?></td>
    <td><?= $ban['board_uri'] ? '/' . h($ban['board_uri']) . '/' : 'Global' ?></td>
    <td><?= h($ban['reason']) ?></td>
    <td><?= $ban['expires_at'] ?? 'Permanent' ?></td>
    <td><?= h($ban['banned_by']) ?></td>
    <td><a href="bans.php?unban=<?= $ban['id'] ?>">[Unban]</a></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<a href="index.php">← Back</a>
</body></html>
