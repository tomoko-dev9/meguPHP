<?php
require_once __DIR__ . '/../includes/core.php';
$title    = SITE_TITLE;
$metadesc = SITE_DESC;
$boards   = get_all_boards();

// Stats per board
$stats = [];
foreach ($boards as $b) {
    $s = db()->prepare("SELECT COUNT(*) as threads,
        (SELECT COUNT(*) FROM posts WHERE board_uri=? AND thread_id IS NOT NULL AND deleted=0) as replies
        FROM posts WHERE board_uri=? AND thread_id IS NULL AND deleted=0");
    $s->execute([$b['uri'], $b['uri']]);
    $stats[$b['uri']] = $s->fetch();
}

// Recent images
$recent_images = db()->query("
    SELECT thumb_name, file_name, board_uri, thread_id, id, board_post_id
    FROM posts
    WHERE thumb_name IS NOT NULL AND thumb_name != '' AND deleted = 0
    ORDER BY id DESC
    LIMIT 3
")->fetchAll();

// Latest posts
$latest_posts = db()->query("
    SELECT p.id, p.board_uri, p.thread_id, p.body, p.created_at, p.board_post_id
    FROM posts p
    WHERE p.deleted = 0 AND p.body != ''
    ORDER BY p.id DESC
    LIMIT 10
")->fetchAll();

// Global stats
$total_posts  = db()->query("SELECT COUNT(*) FROM posts WHERE deleted=0")->fetchColumn();
$total_boards = count($boards);
$pph          = db()->query("SELECT COUNT(*) FROM posts WHERE deleted=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
<meta name="description" content="<?= h($metadesc) ?>">
<meta name="keywords" content="imageboard, anonymous, realtime, liveposting, meguPHP">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
<style>
/* ── Frontpage layout ───────────────────────────────────────── */
body { margin: 0; padding: 0; }

.fp-logo {
    text-align: center;
    padding: 18px 0 8px 0;
}
.fp-logo img {
    max-height: 80px;
    display: inline-block;
}
.fp-logo a { border: none; text-decoration: none; }

#fp-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 10px 30px 10px;
}

/* Boxes */
.m_box, .m_box_red, .box {
    background: rgba(28,29,34,.78);
    border-right: 1px solid #262626;
    border-bottom: 1px solid #262626;
    margin-bottom: 10px;
    padding: 0;
}
.m_box_bar, .m_box_bar_red, .box_bar {
    padding: 4px 12px;
    border-bottom: 1px solid #262626;
}
.m_box_bar h2, .box_bar h2 {
    margin: 0;
    font-size: 13pt;
    color: #357edd;
}
.m_box_bar_red h2 {
    margin: 0;
    font-size: 13pt;
    color: #e55e5e;
}
.m_box p, .m_box_red p, .box p {
    padding: 8px 12px;
    margin: 0;
    color: #8a8a8a;
    font-size: 10pt;
    line-height: 1.6;
}
.m_box p + p, .m_box_red p + p { padding-top: 0; }

/* Two-column layout */
#fp-columns {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
#fp-left {
    display: flex;
    flex-direction: column;
    gap: 3px;
    width: 200px;
    flex-shrink: 0;
}
.indexImageCell a { display: block; }
.indexImageCell img {
    width: 200px;
    height: 150px;
    object-fit: cover;
    display: block;
    border: 1px solid #262626;
}
#fp-right {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Boards grid */
.board-columns {
    display: flex;
    gap: 0;
    padding: 8px 12px;
}
.board-col {
    flex: 1;
}
.board-col h3 {
    margin: 0 0 4px 0;
    font-size: 10pt;
    color: #357edd;
}
.board-col ul {
    list-style: none;
    margin: 0;
    padding: 0;
}
.board-col ul li {
    padding: 1px 0;
    font-size: 10pt;
}

/* Latest posts */
.latestPost {
    padding: 4px 12px;
    border-bottom: 1px solid #2e2e2e;
    font-size: 10pt;
    color: #8a8a8a;
    word-break: break-word;
}
.latestPost:last-child { border-bottom: none; }
.latestPost a { margin-right: 6px; }

/* Stats bar */
#fp-stats {
    text-align: center;
    padding: 8px;
    color: #626262;
    font-size: 9pt;
    border-top: 1px solid #2e2e2e;
    margin-top: 4px;
}

@media (max-width: 640px) {
    #fp-columns { flex-direction: column; }
    #fp-left { flex-direction: row; flex-wrap: wrap; width: 100%; }
    .indexImageCell img { width: 100px; height: 75px; }
    .board-columns { flex-direction: column; }
}
</style>
</head>
<body>

<?= render_nav() ?>

<div class="fp-logo">
    <a href="<?= BASE_URL ?>">
        <img src="<?= BASE_URL ?>public/img/logo.png" alt="<?= h($title) ?>"
             onerror="this.style.display='none'" />
    </a>
</div>

<div id="fp-wrap">

    <!-- About box -->
    <div class="m_box">
        <div class="m_box_bar"><h2>ゆっくりしていってね！</h2></div>
        <p><strong style="color:#b5c8ff;"><?= h($title) ?></strong> — <?= h($metadesc) ?></p>
        <p>Powered by <strong style="color:#b5c8ff;">meguPHP</strong> — a lightweight realtime imageboard engine written in PHP and MySQL, inspired by meguca/doushio. Posts appear live via SSE without refreshing. Tripcodes, capcodes, image search, hover previews, themes, and per-board management included out of the box.</p>
    </div>

    <!-- Two-column section: images left, content right -->
    <div id="fp-columns">

        <!-- Left: recent images -->
        <div id="fp-left">
            <?php foreach ($recent_images as $img):
                $thread = $img['thread_id'] ?? $img['board_post_id'];
            ?>
            <div class="indexImageCell">
                <a href="<?= BASE_URL . h($img['board_uri']) ?>/thread/<?= (int)$thread ?>">
                    <img src="<?= BASE_URL ?>public/uploads/<?= h($img['board_uri']) ?>/<?= h($img['thumb_name']) ?>"
                         alt="recent image" loading="lazy" />
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Right: boards + latest posts -->
        <div id="fp-right">

            <!-- Boards -->
            <div class="box">
                <div class="box_bar"><h2>Boards</h2></div>
                <div class="board-columns">
                    <?php
                    $chunks = array_chunk($boards, max(1, (int)ceil(count($boards) / 3)));
                    foreach ($chunks as $chunk):
                    ?>
                    <div class="board-col">
                        <ul>
                        <?php foreach ($chunk as $b): ?>
                            <li><a href="<?= BASE_URL . h($b['uri']) ?>/">/<?= h($b['uri']) ?>/ — <?= h($b['title']) ?></a>
                            <span style="color:#626262;font-size:9pt;">(<?= number_format($stats[$b['uri']]['threads']) ?>T / <?= number_format($stats[$b['uri']]['replies']) ?>R)</span></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Latest posts -->
            <div class="box">
                <div class="box_bar"><h2>Latest Posts</h2></div>
                <?php foreach ($latest_posts as $p):
                    $thread  = $p['thread_id'] ?? $p['board_post_id'];
                    $snippet = mb_substr(strip_tags($p['body']), 0, 140);
                ?>
                <div class="latestPost">
                    <a href="<?= BASE_URL . h($p['board_uri']) ?>/thread/<?= (int)$thread ?>#<?= (int)$p['board_post_id'] ?>">
                        &gt;&gt;/<?= h($p['board_uri']) ?>/<?= (int)$p['board_post_id'] ?>
                    </a><?= h($snippet) ?>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

    <!-- Software & Privacy -->
    <div class="box">
        <div class="box_bar"><h2>Software &amp; Privacy</h2></div>
        <p>This site runs <strong style="color:#b5c8ff;">meguPHP</strong>, a custom open-source realtime imageboard engine. IPs are stored for moderation purposes only and are never displayed publicly. VPNs are permitted.</p>
    </div>

    <!-- Stats -->
    <div id="fp-stats">
        Total posts: <strong><?= number_format($total_posts) ?></strong>
        &nbsp;|&nbsp; Posts per hour: <strong><?= number_format($pph) ?></strong>
        &nbsp;|&nbsp; Total boards: <strong><?= number_format($total_boards) ?></strong>
    </div>

</div><!-- /#fp-wrap -->

<?= render_nav() ?>
</body>
</html>
