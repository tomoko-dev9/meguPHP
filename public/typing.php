<?php
require_once __DIR__ . '/../includes/core.php';
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$board_uri = preg_replace('/[^a-z0-9]/', '', $_POST['board'] ?? '');
$thread_id = (int)($_POST['thread_id'] ?? 0);
$body      = mb_substr(trim($_POST['body'] ?? ''), 0, 200); // cap at 200 chars
$name      = mb_substr(trim($_POST['name'] ?? 'Anonymous'), 0, 64);
$session   = session_id() ?: bin2hex(random_bytes(8));

if (!$board_uri || !$thread_id) { http_response_code(400); exit; }

// Ensure table exists
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS live_typing (
            id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_uri   VARCHAR(16) NOT NULL,
            thread_id   INT NOT NULL,
            session_key VARCHAR(64) NOT NULL,
            name        VARCHAR(100) NOT NULL DEFAULT 'Anonymous',
            body        TEXT,
            updated_at  DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY uniq_session_thread (session_key, thread_id, board_uri),
            INDEX idx_thread (board_uri, thread_id, updated_at)
        )
    ");
} catch (Exception $e) {}

if ($body === '') {
    // User cleared the box or submitted — remove their typing indicator
    db()->prepare("
        DELETE FROM live_typing
        WHERE session_key = ? AND thread_id = ? AND board_uri = ?
    ")->execute([$session, $thread_id, $board_uri]);
} else {
    db()->prepare("
        INSERT INTO live_typing (board_uri, thread_id, session_key, name, body)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), body = VALUES(body), updated_at = NOW(3)
    ")->execute([$board_uri, $thread_id, $session, $name, $body]);

    // Prune stale typing indicators (older than 8 seconds)
    db()->exec("DELETE FROM live_typing WHERE updated_at < DATE_SUB(NOW(3), INTERVAL 8 SECOND)");
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
