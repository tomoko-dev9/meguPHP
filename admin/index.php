<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin()) redirect(BASE_URL . 'admin/login.php');

// Handle board deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_board'])) {
    $uri = trim($_POST['delete_board']);
    if ($uri) {
        $db = db();
        $db->prepare("DELETE FROM posts WHERE board_uri=?")->execute([$uri]);
        $db->prepare("DELETE FROM boards WHERE uri=?")->execute([$uri]);
        // Remove uploads
        $dir = __DIR__ . '/../public/uploads/' . $uri;
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*.*"));
            rmdir($dir);
        }
        redirect(BASE_URL . 'admin/');
    }
}

$boards  = get_all_boards();
$reports = db()->query("SELECT r.*, p.body FROM reports r LEFT JOIN posts p ON p.board_post_id=r.post_id AND p.board_uri=r.board_uri WHERE r.resolved=0 ORDER BY r.created_at DESC LIMIT 50")->fetchAll();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
    <style>
        .danger { color: #e55e5e; }
        .confirm-delete { display:none; }
        td form { display:inline; }
        .del-btn { background:none; border:none; color:#e55e5e; cursor:pointer; font-size:10pt; padding:0; }
        .del-btn:hover { text-decoration:underline; }
    </style>
</head>
<body>
<div class="admin-bar">
    Logged in as <b><?= h($_SESSION['admin_name']) ?></b> (<?= h(admin_role()) ?>)
    — <a href="<?= BASE_URL ?>admin/logout.php">Logout</a>
    — <a href="<?= BASE_URL ?>">Board</a>
</div>
<h1>Admin Panel</h1>
<h2>Boards</h2>
<table>
<tr><th>URI</th><th>Title</th><th>Posts</th><th>Actions</th></tr>
<?php foreach ($boards as $b): ?>
<tr>
    <td><a href="<?= BASE_URL . h($b['uri']) ?>/">/<?= h($b['uri']) ?>/</a></td>
    <td><?= h($b['title']) ?></td>
    <td><?= number_format($b['post_count']) ?></td>
    <td>
        <a href="manage_board.php?board=<?= h($b['uri']) ?>">[Manage]</a>
        &nbsp;
        <form method="post" onsubmit="return confirm('Permanently delete /<?= h($b['uri']) ?>/ and ALL its posts and files? This cannot be undone.');">
            <input type="hidden" name="delete_board" value="<?= h($b['uri']) ?>">
            <button type="submit" class="del-btn">[Delete]</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</table>
<p><a href="create_board.php">[Create new board]</a></p>

<h2>Open Reports (<?= count($reports) ?>)</h2>
<?php if (!$reports): ?>
<p>No open reports.</p>
<?php else: ?>
<table>
<tr><th>Board</th><th>Post</th><th>Reason</th><th>IP</th><th>Actions</th></tr>
<?php foreach ($reports as $r): ?>
<tr>
    <td>/<?= h($r['board_uri']) ?>/</td>
    <td><a href="<?= BASE_URL . h($r['board_uri']) ?>/thread/<?= $r['post_id'] ?>/#p<?= $r['post_id'] ?>">#<?= $r['post_id'] ?></a></td>
    <td><?= h($r['reason']) ?></td>
    <td><?= h($r['reporter_ip']) ?></td>
    <td>
        <a href="delete.php?board=<?= h($r['board_uri']) ?>&post=<?= $r['post_id'] ?>">[Delete]</a>
        <a href="resolve_report.php?id=<?= $r['id'] ?>">[Resolve]</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Account</h2>
<a href="change_password.php">[Change Password]</a>
<?php if (admin_role() === 'admin'): ?>
| <a href="manage_admins.php">[Manage Admins]</a>
| <a href="bans.php">[Bans]</a>
<?php endif; ?>
</body>
</html>
