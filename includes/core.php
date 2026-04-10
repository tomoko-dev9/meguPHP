<?php
// includes/core.php  —  loaded by every page

require_once __DIR__ . '/../config.php';

// ── PDO connection ────────────────────────────────────────────
$_pdo = null;

function db(): PDO {
    global $_pdo;
    if ($_pdo === null) {
        $dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $_pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=60",
        ]);
    }
    return $_pdo;
}

function db_close(): void {
    global $_pdo;
    $_pdo = null;
}

// ── Session ───────────────────────────────────────────────────
session_name(SESSION_NAME);
session_start();

// ── Helpers ───────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function is_admin(): bool {
    return !empty($_SESSION['admin_id']);
}

function admin_role(): string {
    return $_SESSION['admin_role'] ?? '';
}

function get_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function check_ban(string $board_uri): void {
    $ip  = get_ip();
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare("
        SELECT reason, expires_at FROM bans
        WHERE ip = ?
          AND (board_uri IS NULL OR board_uri = ?)
          AND (expires_at IS NULL OR expires_at > ?)
        LIMIT 1
    ");
    $stmt->execute([$ip, $board_uri, $now]);
    $ban = $stmt->fetch();
    if ($ban) {
        $exp = $ban['expires_at'] ? 'until ' . $ban['expires_at'] : 'permanently';
        die(render_error("You are banned ($exp). Reason: " . h($ban['reason'])));
    }
}

// ── Tripcode ──────────────────────────────────────────────────
function process_name(string $raw, bool $admin = false): array {
    // ## Admin capcode — only honoured if the poster is actually an admin
    if ($admin && preg_match('/^(.*)##\s*Admin\s*$/i', $raw, $m)) {
        return [trim($m[1]) ?: 'Anonymous', '', 'Admin'];
    }
    if (strpos($raw, '#') !== false) {
        [$name, $trip_key] = explode('#', $raw, 2);
        // Strip any ## attempt from non-admins silently
        $trip_key = ltrim($trip_key, '#');
        $trip = '!' . substr(base64_encode(hash('sha256', $trip_key . TRIPCODE_SALT, true)), 0, 10);
        return [trim($name) ?: 'Anonymous', $trip, ''];
    }
    return [trim($raw) ?: 'Anonymous', '', ''];
}

// ── Post formatting ───────────────────────────────────────────
function format_post(string $body, string $board_uri): string {
    $trimmed = trim($body);

    // #flip — must be the only content on the post
    if (strtolower($trimmed) === '#flip') {
        $result = rand(0, 1) ? 'Heads' : 'Tails';
        return '<p><span class="quote-text">#flip → <strong>' . $result . '</strong></span></p>';
    }

    // #8ball — must be the only content on the post
    if (strtolower($trimmed) === '#8ball') {
        $answers = [
            'It is certain.', 'It is decidedly so.', 'Without a doubt.',
            'Yes, definitely.', 'You may rely on it.', 'As I see it, yes.',
            'Most likely.', 'Outlook good.', 'Yes.', 'Signs point to yes.',
            'Reply hazy, try again.', 'Ask again later.', 'Better not tell you now.',
            'Cannot predict now.', 'Concentrate and ask again.',
            "Don't count on it.", 'My reply is no.', 'My sources say no.',
            'Outlook not so good.', 'Very doubtful.',
        ];
        return '<p><span class="quote-text">#8ball → <em>' . $answers[array_rand($answers)] . '</em></span></p>';
    }

    // #NdN dice — e.g. #2d6, #1d20 — must be the only content on the post
    if (preg_match('/^#(\d+)d(\d+)$/i', $trimmed, $m)) {
        $num   = min((int)$m[1], 20);  // cap number of dice at 20
        $sides = min((int)$m[2], 100); // cap sides at 100
        if ($num >= 1 && $sides >= 2) {
            $rolls = [];
            for ($i = 0; $i < $num; $i++) $rolls[] = rand(1, $sides);
            $total = array_sum($rolls);
            $roll_str = implode(', ', $rolls);
            return '<p><span class="quote-text">#' . $num . 'd' . $sides
                 . ' → [' . $roll_str . '] = <strong>' . $total . '</strong></span></p>';
        }
    }

    // Line-by-line formatting
    $lines = explode("\n", h($body));
    $out   = [];
    foreach ($lines as $line) {
        // Post references >>123  (before greentext so >>123 isn't greentexted)
        $line = preg_replace(
            '/&gt;&gt;(\d+)/',
            '<a class="post-ref" href="#p$1">&gt;&gt;$1</a>',
            $line
        );
        // Greentext: lines starting with > but NOT a >>ref link
        if (preg_match('/^&gt;(?!&gt;\d)/', $line)) {
            $line = '<span class="quote-text">' . $line . '</span>';
        }
        // Spoiler [spoiler]text[/spoiler]
        $line = preg_replace('/\[spoiler\](.+?)\[\/spoiler\]/i', '<span class="spoiler">$1</span>', $line);
        // Bold **text**
        $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
        // Italic __text__
        $line = preg_replace('/__(.+?)__/', '<em>$1</em>', $line);
        $out[] = $line;
    }
    return '<p>' . implode('<br>', $out) . '</p>';
}

// ── Next board post ID ────────────────────────────────────────
function next_post_id(string $board_uri): int {
    db()->prepare("
        UPDATE board_post_counter
        SET last_id = LAST_INSERT_ID(last_id + 1)
        WHERE board_uri = ?
    ")->execute([$board_uri]);
    return (int) db()->lastInsertId();
}

// ── Image handling ────────────────────────────────────────────
function handle_upload(array $file, string $board_uri): ?array {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload error: ' . $file['error']);
    if ($file['size'] > MAX_FILE_SIZE) throw new Exception('File too large (max ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB)');

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES, true)) throw new Exception('File type not allowed: ' . $mime);

    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    };

    $hash  = hash_file('sha256', $file['tmp_name']);
    $fname = $hash . '.' . $ext;
    $tname = $hash . 's.' . $ext;
    $dir   = UPLOAD_DIR . $board_uri . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $dest = $dir . $fname;
    if (!file_exists($dest)) {
        move_uploaded_file($file['tmp_name'], $dest);
    }

    [$w, $h] = getimagesize($dest);

    $tdest = $dir . $tname;
    if (!file_exists($tdest)) {
        make_thumbnail($dest, $tdest, $mime, $w, $h);
    }

    return [
        'file_name'     => $fname,
        'file_original' => basename($file['name']),
        'file_size'     => $file['size'],
        'file_w'        => $w,
        'file_h'        => $h,
        'thumb_name'    => $tname,
    ];
}

function make_thumbnail(string $src, string $dest, string $mime, int $w, int $h): void {
    $tw = THUMB_W;
    $th = THUMB_H;

    $ratio = min($tw / $w, $th / $h, 1.0);
    $nw    = (int) round($w * $ratio);
    $nh    = (int) round($h * $ratio);

    $thumb = imagecreatetruecolor($nw, $nh);

    $src_img = match($mime) {
        'image/jpeg' => imagecreatefromjpeg($src),
        'image/png'  => imagecreatefrompng($src),
        'image/gif'  => imagecreatefromgif($src),
        'image/webp' => imagecreatefromwebp($src),
    };

    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $trans = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefilledrectangle($thumb, 0, 0, $nw, $nh, $trans);
    }

    imagecopyresampled($thumb, $src_img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    match($mime) {
        'image/jpeg' => imagejpeg($thumb, $dest, 85),
        'image/png'  => imagepng($thumb, $dest, 7),
        'image/gif'  => imagegif($thumb, $dest),
        'image/webp' => imagewebp($thumb, $dest, 85),
    };

    imagedestroy($thumb);
    imagedestroy($src_img);
}

// ── Fetch board ───────────────────────────────────────────────
function get_board(string $uri): ?array {
    $stmt = db()->prepare("SELECT * FROM boards WHERE uri = ?");
    $stmt->execute([$uri]);
    return $stmt->fetch() ?: null;
}

function get_all_boards(): array {
    return db()->query("SELECT * FROM boards ORDER BY uri")->fetchAll();
}

// ── HTML fragments ────────────────────────────────────────────
function render_nav(string $active_uri = ''): string {
    $boards = get_all_boards();
    $links  = [];
    foreach ($boards as $b) {
        $url     = BASE_URL . $b['uri'] . '/';
        $label   = '[/' . h($b['uri']) . '/]';
        $cls     = ($b['uri'] === $active_uri) ? ' style="color:#af0a0f"' : '';
        $links[] = '<a href="' . $url . '"' . $cls . '>' . $label . '</a>';
    }
    $links[] = '<a href="' . BASE_URL . '">[Home]</a>';
    if (is_admin()) {
        $links[] = '<a href="' . BASE_URL . 'admin/">[Admin]</a>';
        $links[] = '<a href="' . BASE_URL . 'admin/logout.php">[Logout]</a>';
    } else {
        $links[] = '<a href="' . BASE_URL . 'admin/">[Admin login]</a>';
    }
    return '<header><nav>' . implode(' ', $links) . '</nav></header>';
}

function render_error(string $msg): string {
    return '<div class="error-msg">' . $msg . '</div>';
}

function render_post_html(array $post, string $board_uri, bool $is_op = false): string {
    $pid         = (int)$post['board_post_id'];
    $upload_base = BASE_URL . 'uploads/' . h($board_uri) . '/';

    $img_html = '';
    if (!empty($post['file_name'])) {
        $thumb_url = $upload_base . h($post['thumb_name']);
        $full_url  = $upload_base . h($post['file_name']);
        $img_html  = '<div class="post-thumb">'
                   . '<a href="' . $full_url . '" target="_blank">'
                   . '<img src="' . $thumb_url . '" alt="' . h($post['file_original']) . '" '
                   . 'title="' . h($post['file_original']) . ' (' . number_format($post['file_size']) . ' bytes, '
                   . $post['file_w'] . '×' . $post['file_h'] . ')">'
                   . '</a></div>';
    }

    [$dname, $trip] = process_name($post['name']);
    $name_html = '<span class="name">' . h($dname) . '</span>';
    if ($trip) $name_html .= ' <span class="trip">' . h($trip) . '</span>';

    $email = trim($post['email'] ?? '');
    if ($email && $email !== 'sage') {
        $name_html = '<a href="mailto:' . h($email) . '" class="email"><b>' . h($dname) . '</b></a>';
        if ($trip) $name_html .= ' <span class="trip">' . h($trip) . '</span>';
    }

    $subject_html = '';
    if (!empty($post['subject'])) {
        $subject_html = '<span class="subject">' . h($post['subject']) . '</span> ';
    }

    $thread_id   = (int)($post['thread_id'] ?? $pid);
    $thread_link = BASE_URL . $board_uri . '/thread/' . ($is_op ? $pid : $thread_id) . '/';

    $del_check   = '<input type="checkbox" class="del-check" name="delete[]" value="' . $pid . '">';
    $report_link = '<a href="' . BASE_URL . $board_uri . '/report/' . $pid . '" class="act reply-link">Report</a>';
    $admin_del   = is_admin()
        ? ' <a href="' . BASE_URL . 'admin/delete.php?board=' . h($board_uri) . '&post=' . $pid . '" class="act reply-link">Del</a>'
        : '';

    $sticky_badge = ($is_op && !empty($post['sticky'])) ? ' <span style="color:#af0a0f">[Sticky]</span>' : '';
    $locked_badge = ($is_op && !empty($post['locked'])) ? ' <span style="color:#666">[Locked]</span>' : '';

    return '<article id="p' . $pid . '" class="post" data-post-id="' . $pid . '">
        ' . $del_check . '
        <div class="post-info">
            ' . $subject_html . $name_html . '
            <span class="post-time">' . date('m/d/y (D) H:i:s', strtotime($post['created_at'])) . '</span>
            <span class="postnum"><a href="' . $thread_link . '#p' . $pid . '" title="Link to this post">No.</a>'
            . '<a href="#" class="quote-post" data-pid="' . $pid . '">' . $pid . '</a></span>'
            . $sticky_badge . $locked_badge
            . ' ' . $report_link . $admin_del . '
        </div>
        ' . $img_html . '
        <div class="post-body">' . format_post($post['body'], $board_uri) . '</div>
        <div style="clear:both"></div>
    </article>';
}

function post_form_html(string $board_uri, ?int $thread_id = null, bool $locked = false): string {
    if ($locked && !is_admin()) {
        return '<p style="color:#666">This thread is locked.</p>';
    }
    $action    = BASE_URL . $board_uri . '/post.php';
    $tid_input = $thread_id ? '<input type="hidden" name="thread_id" value="' . $thread_id . '">' : '';
    $sub_field = $thread_id ? '' : '
        <div class="form-row"><label>Subject:</label>
        <input type="text" name="subject" maxlength="200"></div>';
    return '<fieldset>
        <legend>' . ($thread_id ? 'Reply' : 'New Thread') . '</legend>
        <form class="post-form" method="post" action="' . $action . '" enctype="multipart/form-data">
        ' . $tid_input . '
        <div class="form-row"><label>Name:</label>
        <input type="text" name="name" maxlength="100" placeholder="Anonymous"></div>
        <div class="form-row"><label>Email:</label>
        <input type="text" name="email" maxlength="200"></div>
        ' . $sub_field . '
        <div class="form-row"><label>Comment:</label><br>
        <textarea name="body" maxlength="8000" required></textarea></div>
        <div class="form-row"><label>File:</label>
        <input type="file" name="file" accept="image/*"></div>
        <div class="form-row">
        <input type="hidden" name="board" value="' . h($board_uri) . '">
        <input type="submit" value="Post">
        </div>
        </form></fieldset>';
}
// ── Capcode badge ─────────────────────────────────────────────
function capcode_html(?string $capcode): string {
    if (!$capcode) return '';
    return ' <b class="admin">## ' . h($capcode) . '</b>';
}
