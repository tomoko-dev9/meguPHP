<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin() || admin_role() !== 'admin') redirect(BASE_URL . 'admin/');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uri   = preg_replace('/[^a-z0-9]/', '', strtolower(trim($_POST['uri'] ?? '')));
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    if (!$uri || !$title) {
        $msg = render_error('URI and title are required.');
    } else {
        try {
            db()->prepare("INSERT INTO boards (uri, title, description) VALUES (?,?,?)")->execute([$uri,$title,$desc]);
            db()->prepare("INSERT IGNORE INTO board_post_counter (board_uri, last_id) VALUES (?,0)")->execute([$uri]);
            redirect(BASE_URL . 'admin/');
        } catch (Exception $e) {
            $msg = render_error('Could not create board (maybe URI already exists).');
        }
    }
}
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Create Board</title>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css"></head>
<body>
<h1>Create Board</h1>
<?= $msg ?>
<fieldset>
<form method="post">
<div class="form-row"><label>URI (e.g. g):</label><input type="text" name="uri" maxlength="10" required pattern="[a-z0-9]+"></div>
<div class="form-row"><label>Title:</label><input type="text" name="title" maxlength="100" required></div>
<div class="form-row"><label>Description:</label><input type="text" name="description" maxlength="255"></div>
<div class="form-row"><input type="submit" value="Create"></div>
</form>
</fieldset>
<a href="index.php">← Back</a>
</body></html>
