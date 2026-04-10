<?php
/**
 * live.php — Server-Sent Events endpoint
 * Emits both 'post' events and 'typing' events.
 */

require_once __DIR__ . '/../includes/core.php';
session_write_close();

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level() > 0) ob_end_clean();

$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$board     = get_board($board_uri);
if (!$board) { http_response_code(404); exit; }

$since     = max(0, (int)($_GET['since']  ?? 0));
$thread_id = isset($_GET['thread']) ? (int)$_GET['thread'] : null;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$retry_ms = defined('SSE_RETRY_MS') ? (int)SSE_RETRY_MS : 3000;
echo "retry: {$retry_ms}\n\n";
flush();

try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS live_payloads (
            live_event_id INT PRIMARY KEY,
            payload       MEDIUMTEXT,
            created_at    DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)
        )
    ");
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
    db_close();
} catch (Exception $e) {}

$last_event_id  = $since;
$start          = time();
$max_duration   = 45;
$last_heartbeat = $start;
$idle_ticks     = 0;
$backoff        = [1, 2, 4, 8];

// Track last typing snapshot so we only emit when it changes
$last_typing_json = '';

while (true) {
    if ((time() - $start) >= $max_duration) {
        echo ": keepalive-close\n\n";
        flush();
        break;
    }
    if (connection_aborted()) break;

    $sleep_s = $backoff[min($idle_ticks, count($backoff) - 1)];
    sleep($sleep_s);

    if (connection_aborted()) break;

    if ((time() - $last_heartbeat) >= 20) {
        echo ": heartbeat\n\n";
        flush();
        $last_heartbeat = time();
        if (connection_aborted()) break;
    }

    try {
        $pdo = db();

        // ── Poll new posts ────────────────────────────────────────────────────
        if ($thread_id !== null) {
            $stmt = $pdo->prepare("
                SELECT le.id, lp.payload
                FROM   live_events   le
                JOIN   live_payloads lp ON lp.live_event_id = le.id
                WHERE  le.board_uri = :board
                  AND  le.thread_id = :thread
                  AND  le.id        > :since
                ORDER  BY le.id ASC
                LIMIT  10
            ");
            $stmt->execute([':board' => $board_uri, ':thread' => $thread_id, ':since' => $last_event_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT le.id, lp.payload
                FROM   live_events   le
                JOIN   live_payloads lp ON lp.live_event_id = le.id
                WHERE  le.board_uri = :board
                  AND  le.id        > :since
                ORDER  BY le.id ASC
                LIMIT  20
            ");
            $stmt->execute([':board' => $board_uri, ':since' => $last_event_id]);
        }
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // ── Poll typing indicators (only for thread view) ─────────────────────
        $typing_rows = [];
        if ($thread_id !== null) {
            $tstmt = $pdo->prepare("
                SELECT name, body
                FROM   live_typing
                WHERE  board_uri  = :board
                  AND  thread_id  = :thread
                  AND  updated_at > DATE_SUB(NOW(3), INTERVAL 8 SECOND)
                  AND  body != ''
                ORDER  BY updated_at ASC
            ");
            $tstmt->execute([':board' => $board_uri, ':thread' => $thread_id]);
            $typing_rows = $tstmt->fetchAll(PDO::FETCH_ASSOC);
            $tstmt->closeCursor();
        }

    } catch (Exception $e) {
        db_close();
        echo ": db-error\n\n";
        flush();
        $idle_ticks = count($backoff) - 1;
        continue;
    }

    db_close();

    // ── Emit post events ──────────────────────────────────────────────────────
    if (!empty($events)) {
        $idle_ticks = 0;
        foreach ($events as $ev) {
            $last_event_id = (int)$ev['id'];
            echo "id: {$last_event_id}\n";
            echo "event: post\n";
            echo "data: {$ev['payload']}\n\n";
        }
        flush();
        if (connection_aborted()) break;
    } else {
        $idle_ticks++;
    }

    // ── Emit typing event only if snapshot changed ────────────────────────────
    $typing_json = json_encode($typing_rows, JSON_UNESCAPED_UNICODE);
    if ($typing_json !== $last_typing_json) {
        $last_typing_json = $typing_json;
        echo "event: typing\n";
        echo "data: {$typing_json}\n\n";
        flush();
        if (connection_aborted()) break;
    }
}
exit;
