<?php
/**
 * live.php — Server-Sent Events endpoint
 *
 * Key optimisations vs previous version:
 *  - DB connection is CLOSED during every sleep interval so it doesn't
 *    hold a MySQL connection slot while just waiting (biggest perf win).
 *  - Reconnects only when actually about to query.
 *  - Exponential idle backoff: 1s → 2s → 4s → 8s (cap 8s).
 *  - Hard 45s ceiling so PHP-FPM workers recycle cleanly.
 *  - Single query path; thread filter applied in SQL not PHP.
 *  - Output buffering fully killed before first byte.
 *  - Heartbeat every 20s (shorter than most proxy 30s idle timeouts).
 */

require_once __DIR__ . '/../includes/core.php';

// ── Release session lock immediately ─────────────────────────────────────────
// core.php calls session_start() which holds an exclusive file lock for the
// entire request. A 45s SSE connection would block every other request from
// the same user (same session) until it closes. Write-close releases the lock
// instantly while keeping $_SESSION readable for the rest of this request.
session_write_close();

// ── Kill every output buffer layer ───────────────────────────────────────────
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level() > 0) ob_end_clean();

// ── Validate board ────────────────────────────────────────────────────────────
$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$board     = get_board($board_uri);
if (!$board) { http_response_code(404); exit; }

$since     = max(0, (int)($_GET['since']  ?? 0));
$thread_id = isset($_GET['thread']) ? (int)$_GET['thread'] : null;

// ── SSE headers ───────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');          // tell nginx not to buffer this
header('Connection: keep-alive');

$retry_ms = defined('SSE_RETRY_MS') ? (int)SSE_RETRY_MS : 3000;
echo "retry: {$retry_ms}\n\n";
flush();

// ── Ensure live_payloads table exists (cheap IF NOT EXISTS, runs once) ────────
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS live_payloads (
            live_event_id INT PRIMARY KEY,
            payload       MEDIUMTEXT,
            created_at    DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)
        )
    ");
    // Explicitly close the connection so we don't hold it during the first sleep
    db_close();
} catch (Exception $e) { /* ignore */ }

// ── Loop config ───────────────────────────────────────────────────────────────
$last_event_id  = $since;
$start          = time();
$max_duration   = 45;          // seconds before we tell the client to reconnect
$last_heartbeat = $start;
$idle_ticks     = 0;
// Backoff ladder (seconds): 1, 2, 4, 8, 8, 8 …
$backoff        = [1, 2, 4, 8];

// ── Main event loop ───────────────────────────────────────────────────────────
while (true) {

    // Hard ceiling
    if ((time() - $start) >= $max_duration) {
        echo ": keepalive-close\n\n";
        flush();
        break;
    }

    // Abort if client disconnected
    if (connection_aborted()) break;

    // Sleep WITHOUT holding a DB connection
    $sleep_s = $backoff[min($idle_ticks, count($backoff) - 1)];
    sleep($sleep_s);

    if (connection_aborted()) break;

    // Heartbeat (comment line — zero bandwidth, keeps proxies alive)
    if ((time() - $last_heartbeat) >= 20) {
        echo ": heartbeat\n\n";
        flush();
        $last_heartbeat = time();
        if (connection_aborted()) break;
    }

    // ── Open a fresh DB connection, query, then immediately close ─────────────
    try {
        $pdo = db(); // re-opens (or returns cached PDO — see note below)

        if ($thread_id !== null) {
            $stmt = $pdo->prepare("
                SELECT le.id, lp.payload
                FROM   live_events  le
                JOIN   live_payloads lp ON lp.live_event_id = le.id
                WHERE  le.board_uri = :board
                  AND  le.thread_id = :thread
                  AND  le.id        > :since
                ORDER  BY le.id ASC
                LIMIT  10
            ");
            $stmt->execute([
                ':board'  => $board_uri,
                ':thread' => $thread_id,
                ':since'  => $last_event_id,
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT le.id, lp.payload
                FROM   live_events  le
                JOIN   live_payloads lp ON lp.live_event_id = le.id
                WHERE  le.board_uri = :board
                  AND  le.id        > :since
                ORDER  BY le.id ASC
                LIMIT  20
            ");
            $stmt->execute([
                ':board' => $board_uri,
                ':since' => $last_event_id,
            ]);
        }

        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

    } catch (Exception $e) {
        // DB hiccup — log quietly, back off, try again next tick
        db_close();
        echo ": db-error\n\n";
        flush();
        $idle_ticks = count($backoff) - 1; // jump to max backoff
        continue;
    }

    // Close the connection immediately — don't hold it during next sleep
    db_close();

    // ── Emit events ───────────────────────────────────────────────────────────
    if (!empty($events)) {
        $idle_ticks = 0;                   // reset backoff on activity
        foreach ($events as $ev) {
            $last_event_id = (int)$ev['id'];
            echo "id: {$last_event_id}\n";
            echo "event: post\n";
            echo "data: {$ev['payload']}\n\n";
        }
        flush();
        if (connection_aborted()) break;
    } else {
        $idle_ticks++;                     // advance backoff
    }
}
// PHP cleans up automatically; explicit exit keeps worker tidy
exit;