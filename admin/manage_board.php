<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin()) redirect(BASE_URL . 'admin/login.php');

$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$board = get_board($board_uri);
if (!$board) { die('Board not found.'); }

$msg = '';

// Handle actions
$action  = $_GET['action']  ?? '';
$post_id = (int)($_GET['post'] ?? 0);

if ($action === 'sticky' && $post_id) {
    $cur = db()->prepare("SELECT sticky FROM posts WHERE board_uri=? AND board_post_id=?");
    $cur->execute([$board_uri, $post_id]);
    $row = $cur->fetch();
    $new = $row ? (int)!$row['sticky'] : 1;
    db()->prepare("UPDATE posts SET sticky=? WHERE board_uri=? AND board_post_id=?")->execute([$new, $board_uri, $post_id]);
    $msg = '<div class="notice-msg">Thread ' . ($new ? 'stickied' : 'unstickied') . '.</div>';
}

if ($action === 'lock' && $post_id) {
    $cur = db()->prepare("SELECT locked FROM posts WHERE board_uri=? AND board_post_id=?");
    $cur->execute([$board_uri, $post_id]);
    $row = $cur->fetch();
    $new = $row ? (int)!$row['locked'] : 1;
    db()->prepare("UPDATE posts SET locked=? WHERE board_uri=? AND board_post_id=?")->execute([$new, $board_uri, $post_id]);
    $msg = '<div class="notice-msg">Thread ' . ($new ? 'locked' : 'unlocked') . '.</div>';
}

if ($action === 'delete' && $post_id) {
    db()->prepare("UPDATE posts SET deleted=1 WHERE board_uri=? AND board_post_id=?")->execute([$board_uri, $post_id]);
    db()->prepare("UPDATE posts SET deleted=1 WHERE board_uri=? AND thread_id=?")->execute([$board_uri, $post_id]);
    $msg = '<div class="notice-msg">Thread deleted.</div>';
}

// Fetch threads
$threads = db()->prepare("SELECT * FROM posts WHERE board_uri=? AND thread_id IS NULL AND deleted=0 ORDER BY sticky DESC, last_reply DESC LIMIT 50");
$threads->execute([$board_uri]);
$threads = $threads->fetchAll();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Manage /<?= h($board_uri) ?>/</title>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css"></head>
<body>
<h1>Manage /<?= h($board_uri) ?>/ — <?= h($board['title']) ?></h1>
<?= $msg ?>

<table>
<tr><th>#</th><th>Subject/Body</th><th>Replies</th><th>Last Reply</th><th>Sticky</th><th>Locked</th><th>Actions</th></tr>
<?php foreach ($threads as $t): ?>
<?php $pid = (int)$t['board_post_id']; ?>
<tr>
    <td><?= $pid ?></td>
    <td><a href="<?= BASE_URL . $board_uri ?>/thread/<?= $pid ?>/">
        <?= h(mb_substr($t['subject'] ?: $t['body'], 0, 60)) ?>
    </a></td>
    <td><?= $t['reply_count'] ?></td>
    <td><?= $t['last_reply'] ?></td>
    <td><?= $t['sticky'] ? '✓' : '' ?></td>
    <td><?= $t['locked'] ? '✓' : '' ?></td>
    <td>
        <a href="?board=<?= h($board_uri) ?>&action=sticky&post=<?= $pid ?>">[<?= $t['sticky'] ? 'Unsticky' : 'Sticky' ?>]</a>
        <a href="?board=<?= h($board_uri) ?>&action=lock&post=<?= $pid ?>">[<?= $t['locked'] ? 'Unlock' : 'Lock' ?>]</a>
        <a href="?board=<?= h($board_uri) ?>&action=delete&post=<?= $pid ?>" onclick="return confirm('Delete this thread?')">[Delete]</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
<a href="index.php">← Back to Admin</a>
</body></html>
