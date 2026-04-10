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
        $dir = __DIR__ . '/../public/uploads/' . $uri;
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*.*"));
            rmdir($dir);
        }
        redirect(BASE_URL . 'admin/');
    }
}

$boards = get_all_boards();

// Fetch reports joined with post data — get thread_id so we can build a correct link
$reports = db()->query("
    SELECT r.*,
           p.body,
           p.thread_id,
           COALESCE(p.thread_id, r.post_id) AS link_thread_id
    FROM reports r
    LEFT JOIN posts p ON p.board_post_id = r.post_id AND p.board_uri = r.board_uri
    WHERE r.resolved = 0
    ORDER BY r.created_at DESC
    LIMIT 50
")->fetchAll();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
    <style>
        body { background:#1e1e1e; color:#389eb6; font-family:"Helvetica Neue",Helvetica,Arial,sans-serif; font-size:10pt; margin:0; padding:0; }
        a { color:#b5c8ff; } a:hover { color:#af005f; }
        h1,h2 { color:#357edd; }
        .admin-bar { background:rgba(46,46,46,0.9); border-bottom:1px solid #2a2a2a; padding:4px 10px; font-size:9pt; }
        .admin-bar b { color:#add8e6; }
        .wrap { padding:10px 16px; }

        table { border-collapse:collapse; width:100%; margin-bottom:12px; font-size:9pt; }
        th { background:#252525; color:#b5c8ff; text-align:left; padding:5px 8px; border-bottom:1px solid #333; }
        td { padding:5px 8px; border-bottom:1px solid #252525; vertical-align:top; }
        tr:hover td { background:rgba(255,255,255,0.02); }

        .danger { color:#e55e5e; }
        td form { display:inline; }
        .del-btn { background:none; border:none; color:#af005f; cursor:pointer; font-size:9pt; padding:0; }
        .del-btn:hover { text-decoration:underline; }

        .post-preview {
            max-width:340px; color:#8a8a8a; font-size:8pt;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .report-actions a { margin-right:6px; font-size:9pt; white-space:nowrap; }
        .resolve-link { color:#12bd7c !important; }
        .resolve-link:hover { color:#af005f !important; }
        .report-ip { color:#626262; font-size:8pt; }
        .report-time { color:#626262; font-size:8pt; white-space:nowrap; }
        .no-reports { color:#626262; font-size:9pt; padding:6px 0; }
    </style>
</head>
<body>
<div class="admin-bar">
    Logged in as <b><?= h($_SESSION['admin_name']) ?></b> (<?= h(admin_role()) ?>)
    — <a href="<?= BASE_URL ?>admin/logout.php">Logout</a>
    — <a href="<?= BASE_URL ?>">Board</a>
</div>
<div class="wrap">
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
<p class="no-reports">No open reports.</p>
<?php else: ?>
<table>
<tr><th>Time</th><th>Board</th><th>Post</th><th>Body</th><th>Reason</th><th>IP</th><th>Actions</th></tr>
<?php foreach ($reports as $r):
    $thread_url = BASE_URL . h($r['board_uri']) . '/thread/' . (int)$r['link_thread_id'] . '/#p' . (int)$r['post_id'];
    $preview    = $r['body'] ? mb_substr(strip_tags($r['body']), 0, 120) : '(deleted)';
?>
<tr>
    <td class="report-time"><?= h(date('m/d H:i', strtotime($r['created_at']))) ?></td>
    <td>/<?= h($r['board_uri']) ?>/</td>
    <td><a href="<?= $thread_url ?>" target="_blank">#<?= (int)$r['post_id'] ?></a></td>
    <td><div class="post-preview" title="<?= h($preview) ?>"><?= h($preview) ?></div></td>
    <td><?= h($r['reason']) ?></td>
    <td class="report-ip"><?= h($r['reporter_ip']) ?></td>
    <td class="report-actions">
        <a href="<?= BASE_URL ?>admin/delete.php?board=<?= urlencode($r['board_uri']) ?>&post=<?= (int)$r['post_id'] ?>" onclick="return confirm('Delete post #<?= (int)$r['post_id'] ?>?')">[Delete post]</a>
        <a href="resolve_report.php?id=<?= (int)$r['id'] ?>" class="resolve-link">[Resolve]</a>
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
</div>
</body>
</html>
