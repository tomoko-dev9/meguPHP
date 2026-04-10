<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin()) redirect(BASE_URL . 'admin/login.php');

$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$post_id   = (int)($_GET['post'] ?? 0);

if ($board_uri && $post_id) {
    // Mark deleted
    db()->prepare("UPDATE posts SET deleted=1 WHERE board_uri=? AND board_post_id=?")
        ->execute([$board_uri, $post_id]);
    // If it's an OP also delete replies
    db()->prepare("UPDATE posts SET deleted=1 WHERE board_uri=? AND thread_id=?")
        ->execute([$board_uri, $post_id]);
}

redirect(BASE_URL . 'admin/');
