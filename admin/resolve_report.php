<?php
require_once __DIR__ . '/../includes/core.php';
if (!is_admin()) redirect(BASE_URL . 'admin/login.php');

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    db()->prepare("UPDATE reports SET resolved=1 WHERE id=?")->execute([$id]);
}
redirect(BASE_URL . 'admin/');
