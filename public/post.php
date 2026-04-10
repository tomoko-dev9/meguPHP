<?php
require_once __DIR__ . '/../includes/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL);
}

// Resolve board_uri
$board_uri = '';
if (!empty($_POST['_board'])) {
    $board_uri = preg_replace('/[^a-z0-9]/', '', $_POST['_board']);
} elseif (!empty($_POST['board'])) {
    $board_uri = preg_replace('/[^a-z0-9]/', '', $_POST['board']);
} else {
    $parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
    foreach ($parts as $part) {
        if (preg_match('/^[a-z0-9]+$/', $part) && $part !== 'post') {
            $board_uri = $part;
            break;
        }
    }
}

if ($board_uri === '') die(render_error('Could not determine board.'));

$board = get_board($board_uri);
if (!$board) die(render_error('Invalid board: ' . h($board_uri)));

check_ban($board_uri);

$thread_id = isset($_POST['thread_id']) && $_POST['thread_id'] !== ''
    ? (int)$_POST['thread_id']
    : null;

$raw_name = trim($_POST['name'] ?? '');
[$name, $tripcode_val, $capcode] = process_name($raw_name, is_admin());
$name = $name ?: 'Anonymous';
$email   = trim($_POST['email']   ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = trim($_POST['body']    ?? '');

if (mb_strlen($body) < 1)    die(render_error('Comment is required.'));
if (mb_strlen($body) > 8000) die(render_error('Comment is too long.'));

// Verify thread exists if replying
$op = null;
if ($thread_id !== null) {
    $op_stmt = db()->prepare("
        SELECT * FROM posts
        WHERE board_uri=? AND board_post_id=? AND thread_id IS NULL AND deleted=0
    ");
    $op_stmt->execute([$board_uri, $thread_id]);
    $op = $op_stmt->fetch();
    if (!$op)                                  die(render_error('Thread not found.'));
    if (!empty($op['locked']) && !is_admin())  die(render_error('Thread is locked.'));
}

// Handle file upload
$file_data = null;
$file_key  = !empty($_FILES['image']['name']) ? 'image' : (!empty($_FILES['file']['name']) ? 'file' : null);
if ($file_key !== null) {
    try {
        $file_data = handle_upload($_FILES[$file_key], $board_uri);
    } catch (Exception $e) {
        die(render_error('Upload failed: ' . h($e->getMessage())));
    }
}

$ip = get_ip();

// -- Ensure SSE tables exist BEFORE opening the transaction -------------------
// DDL (CREATE TABLE) causes an implicit commit in MySQL — always run outside
// any open transaction to avoid silently ending it.
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS live_payloads (
            live_event_id INT PRIMARY KEY,
            payload       MEDIUMTEXT,
            created_at    DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)
        )
    ");
} catch (Exception $e) { /* already exists — ignore */ }

try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS live_events (
            id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_uri  VARCHAR(16)  NOT NULL,
            thread_id  INT          NOT NULL,
            post_id    INT          NOT NULL,
            created_at DATETIME(3)  DEFAULT CURRENT_TIMESTAMP(3),
            INDEX idx_board_thread (board_uri, thread_id, id),
            INDEX idx_board        (board_uri, id)
        )
    ");
} catch (Exception $e) { /* already exists — ignore */ }

// -- Main transaction ----------------------------------------------------------
try {
    db()->beginTransaction();

    $new_post_id = next_post_id($board_uri);

    db()->prepare("
        INSERT INTO posts
            (board_uri, board_post_id, thread_id, subject, name, email, body,
             file_name, file_original, file_size, file_w, file_h, thumb_name, ip, capcode)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $board_uri,
        $new_post_id,
        $thread_id,
        $subject,
        $name,
        $email,
        $body,
        $file_data['file_name']     ?? null,
        $file_data['file_original'] ?? null,
        $file_data['file_size']     ?? null,
        $file_data['file_w']        ?? null,
        $file_data['file_h']        ?? null,
        $file_data['thumb_name']    ?? null,
        $ip,
        $capcode ?: null,
    ]);

    // Update thread stats
    if ($thread_id !== null) {
        $sage = strtolower($email) === 'sage';
        if ($sage) {
            db()->prepare("
                UPDATE posts SET reply_count = reply_count + 1
                WHERE board_uri = ? AND board_post_id = ?
            ")->execute([$board_uri, $thread_id]);
        } else {
            db()->prepare("
                UPDATE posts SET reply_count = reply_count + 1, last_reply = NOW()
                WHERE board_uri = ? AND board_post_id = ?
            ")->execute([$board_uri, $thread_id]);
        }
        if ($file_data) {
            db()->prepare("
                UPDATE posts SET image_count = image_count + 1
                WHERE board_uri = ? AND board_post_id = ?
            ")->execute([$board_uri, $thread_id]);
        }
    }

    $actual_thread = $thread_id ?? $new_post_id;

    // Insert live event — this is what live.php polls for
    db()->prepare("
        INSERT INTO live_events (board_uri, thread_id, post_id) VALUES (?,?,?)
    ")->execute([$board_uri, $actual_thread, $new_post_id]);
    $live_event_id = (int)db()->lastInsertId();

    db()->commit();

} catch (Exception $e) {
    if (db()->inTransaction()) db()->rollBack();
    die(render_error('Database error: ' . h($e->getMessage())));
}

// -- Build and store SSE payload (outside transaction — non-fatal) -------------
try {
    $row_stmt = db()->prepare("SELECT * FROM posts WHERE board_uri = ? AND board_post_id = ?");
    $row_stmt->execute([$board_uri, $new_post_id]);
    $new_post_row = $row_stmt->fetch();

    $post_html = render_post_html($new_post_row, $board_uri, $thread_id === null);

    $payload = json_encode([
        'live_event_id' => $live_event_id,
        'thread_id'     => $actual_thread,
        'post_id'       => $new_post_id,
        'post_html'     => $post_html,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    db()->prepare("
        INSERT INTO live_payloads (live_event_id, payload)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE payload = VALUES(payload)
    ")->execute([$live_event_id, $payload]);

    // Prune old payloads (keep last 10 min)
    db()->exec("DELETE FROM live_payloads WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

} catch (Exception $e) {
    // Non-fatal: post was committed, just SSE delivery won't work for this post
    error_log('[live_payload] ' . $e->getMessage());
}

// -- Redirect ------------------------------------------------------------------
if ($thread_id !== null) {
    redirect(BASE_URL . $board_uri . '/thread/' . $thread_id . '/#p' . $new_post_id);
} else {
    redirect(BASE_URL . $board_uri . '/thread/' . $new_post_id . '/');
}