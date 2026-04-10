<?php
require_once __DIR__ . '/../includes/core.php';

$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$post_id   = (int)($_GET['post'] ?? 0);
$board     = get_board($board_uri);

if (!$board || !$post_id) { die('Invalid request.'); }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');
    if (!$reason) { $msg = render_error('Please provide a reason.'); }
    else {
        db()->prepare("INSERT INTO reports (post_id, board_uri, reason, reporter_ip) VALUES (?,?,?,?)")
            ->execute([$post_id, $board_uri, $reason, get_ip()]);
        redirect(BASE_URL . $board_uri . '/');
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Report — /<?= h($board_uri) ?>/</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
</head>
<body>
<?= render_nav($board_uri) ?>
<h1>Report Post #<?= $post_id ?></h1>
<?= $msg ?>
<fieldset>
<form method="post">
<div class="form-row"><label>Reason:</label>
<input type="text" name="reason" maxlength="300" style="width:300px" required></div>
<div class="form-row"><input type="submit" value="Submit Report"></div>
</form>
</fieldset>
</body>
</html>
