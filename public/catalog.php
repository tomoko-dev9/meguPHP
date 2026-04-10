<?php
require_once __DIR__ . '/../includes/core.php';

$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$board = get_board($board_uri);
if (!$board) { http_response_code(404); die('Board not found'); }

// ── Banner upload (admin only) ────────────────────────────────
$banner_msg = '';
if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['banner'])) {
    $f = $_FILES['banner'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $mime = mime_content_type($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
            $banner_msg = 'error:File type not allowed.';
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            $banner_msg = 'error:Banner must be under 2 MB.';
        } else {
            $ext = match($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            };
            $banner_dir = __DIR__ . '/../banners/';
            if (!is_dir($banner_dir)) mkdir($banner_dir, 0755, true);
            foreach (glob($banner_dir . 'banner-' . $board_uri . '.*') as $old) unlink($old);
            $dest = $banner_dir . 'banner-' . $board_uri . '.' . $ext;
            move_uploaded_file($f['tmp_name'], $dest);
            $banner_msg = 'ok:Banner updated successfully.';
        }
    } else {
        $banner_msg = 'error:Upload failed (error code ' . $f['error'] . ').';
    }
}

$threads_stmt = db()->prepare("
    SELECT * FROM posts
    WHERE board_uri = ? AND thread_id IS NULL AND deleted = 0
    ORDER BY sticky DESC, last_reply DESC
    LIMIT 150
");
$threads_stmt->execute([$board_uri]);
$threads = $threads_stmt->fetchAll();

session_write_close();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>/<?= h($board_uri) ?>/ - Catalog</title>
    <meta name="viewport" content="width=device-width, minimum-scale=1.0, maximum-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/style.css">
    <link rel="stylesheet" id="theme">
    <style>
        /* ── moe base ── */
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            background-color: #eef2ff;
            background-image: none;
            margin: 0; padding: 0;
        }
        a { color: #34345c; text-decoration: none; }
        a:hover { color: #d00; }
        b { color: #117743; }
        b.admin { color: #f00000; }
        b.moderator { color: purple; }
        em { color: #789922; }
        hr { border: 0; border-top: 1px solid #b7c5d9; margin: 4px 0; }
        h1 {
            color: #af0a0f;
            font: bolder 28px Tahoma, sans-serif;
            letter-spacing: -2px;
            text-shadow: none;
            margin: 0;
        }

        /* ── Banner bar ── */
        #banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            box-sizing: border-box;
            padding: 3px 8px;
            font-size: 9pt;
            background: rgba(214,218,240,0.9);
            border-bottom: 1px solid #b7c5d9;
            color: #000;
        }
        #navTop { flex: 0 0 auto; }
        #navTop a { color: #34345c; }
        #navTop a:hover { color: #d00; }
        #banner_center { flex: 1 1 auto; text-align: center; color: #34345c; }
        .bfloat-group { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; }
        .bfloat { cursor: pointer; }
        .bfloat svg { fill: #34345c; vertical-align: middle; }
        .bfloat:hover svg { fill: #d00; }
        #sync { color: #888; font-size: 8.5pt; }
        #sync.connected { color: #117743; }
        #sync.error { color: #d00; }
        #onlineCount { color: #888; font-size: 8.5pt; }
        #banner_FAQ { color: #34345c !important; }

        /* ── Board header ── */
        #board-header { text-align: center; margin: 8px 0 4px 0; }
        #board-header img { display: block; margin: 0 auto 4px auto; max-width: 400px; }

        /* ── Thread nav ── */
        threads h1 {
            font-family: Tahoma, sans-serif;
            font-size: 1.4em;
            color: #af0a0f;
            text-align: center;
            margin: 8px 0 2px 0;
        }
        aside.act.compact {
            text-align: center;
            font-size: 9pt;
            margin: 2px 0;
            color: #34345c;
        }
        aside.act.compact a { color: #34345c; }
        aside.act.compact a:hover { color: #d00; }

        /* ── Catalog grid ── */
        #catalog {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            padding: 4px 8px;
        }
        #catalog article {
            width: 160px;
            vertical-align: top;
            display: inline-block;
            font-size: 8.5pt;
            padding: 6px 4px 8px 4px;
            box-sizing: border-box;
            background: transparent;
            border: 1px solid transparent;
            transition: border-color .15s;
            position: relative;
        }
        #catalog article:hover {
            border-color: #b7c5d9;
            background: rgba(214,218,240,0.5);
        }
        #catalog article > a { display: block; text-align: left; }
        #catalog article img.expanded {
            display: block;
            max-width: 150px;
            max-height: 150px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin-bottom: 3px;
            background: #d6daf0;
            border: 1px solid #b7c5d9;
        }
        .cat-no-thumb {
            width: 150px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #d6daf0;
            border: 1px solid #b7c5d9;
            color: #888;
            font-size: 8pt;
            margin-bottom: 3px;
        }
        #catalog article small {
            display: block;
            color: #34345c;
            font-size: 8pt;
            margin-bottom: 2px;
            line-height: 1.4;
        }
        #catalog article small .act.expansionLinks a { color: #34345c; }
        #catalog article small .act.expansionLinks a:hover { color: #d00; }
        #catalog article h3 {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0f0c5d;
            margin: 2px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 152px;
        }
        #catalog article .cat-snippet {
            color: #555;
            font-size: 7.5pt;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
            max-width: 152px;
        }
        .cat-sticky-pin { color: #af0a0f; font-size: 7.5pt; }

        /* ── Admin banner upload ── */
        #banner-upload-toggle { display: inline-block; margin: 4px 8px; font-size: 9pt; cursor: pointer; }
        #banner-upload-panel {
            display: none;
            margin: 4px 8px 8px 8px;
            background: #fff;
            border: 1px solid #b7c5d9;
            padding: 10px 14px;
            font-size: 9pt;
            color: #333;
            max-width: 420px;
        }
        #banner-upload-panel b { color: #af0a0f; display: block; margin-bottom: 6px; }
        #banner-upload-panel input[type="file"] { color: #333; margin-bottom: 6px; display: block; }
        #banner-upload-panel input[type="submit"] {
            background: #eef2ff; color: #34345c;
            border: 1px solid #b7c5d9; padding: 2px 10px;
            cursor: pointer; font-size: 9pt;
        }
        #banner-upload-panel input[type="submit"]:hover { background: #d6daf0; }
        .banner-msg-ok    { color: #117743; margin-top: 6px; font-size: 9pt; }
        .banner-msg-error { color: #d00; margin-top: 6px; font-size: 9pt; }

        /* ── Modals ── */
        .bmodal {
            display: none;
            position: fixed;
            top: 30px;
            right: 8px;
            z-index: 9999;
            background: #fff;
            border: 1px solid #b7c5d9;
            padding: 10px 14px;
            font-size: 9pt;
            color: #333;
        }
        #identity { right: 40px; }
        #faq-panel { right: 80px; max-width: 340px; line-height: 1.7; }
        #options-panel { min-width: 200px; }
        .bmodal b { color: #af0a0f; display: block; margin-bottom: 8px; }
        .bmodal label { display: block; margin-bottom: 4px; color: #333; }
        .bmodal hr { border-top: 1px solid #b7c5d9; margin: 8px 0; }
        .bmodal select { background: #fff; color: #333; border: 1px solid #b7c5d9; margin-left: 4px; }

        @media (max-width: 600px) {
            #catalog article { width: calc(50% - 4px); }
            #catalog article img.expanded,
            .cat-no-thumb { max-width: 100%; width: 100%; }
        }
    </style>
</head>
<body>

<!-- Identity modal -->
<fieldset id="identity" class="bmodal">
    <label for="name" class="ident">Name:</label> <input id="name" name="name"><br>
    <label for="email" class="ident">Email:</label> <input id="email" name="email"><br>
</fieldset>

<div id="hover_overlay"></div>
<div id="user_bg"></div>

<!-- Top banner bar -->
<span id="banner">
    <b id="navTop"><?= render_nav($board_uri) ?></b>
    <b id="banner_center"></b>
    <span class="bfloat-group">
        <a id="banner_FAQ" class="bfloat" title="Formatting help" style="font-style:italic;font-weight:bold;font-size:10pt;color:#34345c;line-height:1;">i</a>
        <a id="banner_identity" class="bfloat" title="Identity">
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8">
                <path d="M4 0c-1.1 0-2 1.12-2 2.5s.9 2.5 2 2.5 2-1.12 2-2.5-.9-2.5-2-2.5zm-2.09 5c-1.06.05-1.91.92-1.91 2v1h8v-1c0-1.08-.84-1.95-1.91-2-.54.61-1.28 1-2.09 1-.81 0-1.55-.39-2.09-1z" />
            </svg>
        </a>
        <a id="options" class="bfloat" title="Options">
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8">
                <path d="M5.5 0c-1.38 0-2.5 1.12-2.5 2.5 0 .32.08.62.19.91l-2.91 2.88c-.39.39-.39 1.05 0 1.44.2.2.46.28.72.28.26 0 .52-.09.72-.28l2.88-2.91c.28.11.58.19.91.19 1.38 0 2.5-1.12 2.5-2.5 0-.16 0-.32-.03-.47l-.97.97h-2v-2l.97-.97c-.15-.03-.31-.03-.47-.03zm-4.5 6.5c.28 0 .5.22.5.5s-.22.5-.5.5-.5-.22-.5-.5.22-.5.5-.5z"/>
            </svg>
        </a>
        <b id="onlineCount" title="Online Counter">[0]</b>
        <b id="sync">Not synched</b>
    </span>
</span>

<!-- FAQ modal -->
<div id="faq-panel" class="bmodal" style="right:80px;">
    <b>Post Formatting</b>
    <table style="border-collapse:collapse;width:100%;">
        <tr><td style="color:#34345c;padding-right:12px;">&gt;text</td><td>Greentext quote</td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">&gt;&gt;123</td><td>Link to post #123</td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">**text**</td><td><b>Bold</b></td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">__text__</td><td><em>Italic</em></td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">[spoiler]text[/spoiler]</td><td><span style="background:#333;color:#333;">Spoiler</span></td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">#flip</td><td>Coin flip</td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">#2d6</td><td>Roll 2d6 dice</td></tr>
        <tr><td style="color:#34345c;padding-right:12px;">#8ball</td><td>Magic 8-ball</td></tr>
    </table>
    <hr>
    <div style="color:#888;">Max upload: 30 MB · JPG PNG GIF WEBP</div>
</div>

<!-- Options panel -->
<div id="options-panel" class="bmodal">
    <b>Options</b>
    <label><input type="checkbox" id="opt-anon"> Anonymise names</label>
    <label><input type="checkbox" id="opt-hidethumbs"> Hide thumbnails</label>
    <hr>
    <label>Theme:
        <select id="theme-select">
            <option value="ocean">ocean</option>
            <option value="moe">moe</option>
            <option value="gar">gar</option>
            <option value="moon">moon</option>
            <option value="ashita">ashita</option>
            <option value="console">console</option>
            <option value="tea">tea</option>
            <option value="higan">higan</option>
            <option value="rave">rave</option>
        </select>
    </label>
</div>

<div id="headerTopMargin"></div>

<h1>
    <img src="<?= BASE_URL ?>banners/banner-<?= h($board_uri) ?>.png"
         onerror="this.style.display='none'" alt="" id="board-banner"
         style="display:block;margin:0 auto;max-width:100%;">
</h1>

<threads>
    <h1>/<?= h($board_uri) ?>/<?php if (!empty($board['title'])): ?> - <?= h($board['title']) ?><?php endif; ?></h1>

    <aside class="act compact">
        <a href="<?= BASE_URL . h($board_uri) ?>/" class="history">Return</a>
        <?php if (is_admin()): ?>
        &nbsp;|&nbsp;<a href="#" id="banner-upload-toggle">[Upload banner]</a>
        <?php endif; ?>
    </aside>

    <?php if (is_admin()): ?>
    <div id="banner-upload-panel">
        <b>Upload Board Banner</b>
        <form method="post" enctype="multipart/form-data"
              action="<?= BASE_URL . h($board_uri) ?>/catalog<?= '?board=' . h($board_uri) ?>">
            <input type="hidden" name="_board" value="<?= h($board_uri) ?>">
            <input type="file" name="banner" accept="image/jpeg,image/png,image/gif,image/webp">
            <div style="color:#888;font-size:8pt;margin-bottom:6px;">
                Recommended: 300×100 px · JPG PNG GIF WEBP · max 2 MB
            </div>
            <input type="submit" value="Upload Banner">
        </form>
        <?php if ($banner_msg): ?>
            <?php [$type, $msg] = explode(':', $banner_msg, 2); ?>
            <div class="banner-msg-<?= $type ?>"><?= h($msg) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <hr>

    <div id="catalog">
    <?php foreach ($threads as $t):
        $tid   = (int)$t['board_post_id'];
        $subj  = trim($t['subject'] ?? '');
        $body  = trim($t['body'] ?? '');
        $rc    = (int)($t['reply_count'] ?? 0);
        $ic    = (int)($t['image_count'] ?? 0);
        $sticky = !empty($t['sticky']);
        $thread_url = BASE_URL . h($board_uri) . '/thread/' . $tid . '/';
        $tw = 150; $th = 150;
        if (!empty($t['thumb_width']) && !empty($t['thumb_height'])) {
            $ratio = $t['thumb_width'] / $t['thumb_height'];
            if ($ratio > 1) { $th = round(150 / $ratio); }
            else            { $tw = round(150 * $ratio); }
        }
    ?>
    <article>
        <a href="<?= $thread_url ?>" class="history" target="_blank" rel="nofollow">
            <?php if (!empty($t['thumb_name'])): ?>
                <img src="<?= BASE_URL ?>uploads/<?= h($board_uri) ?>/<?= h($t['thumb_name']) ?>"
                     width="<?= $tw ?>" height="<?= $th ?>" class="expanded" alt="">
            <?php else: ?>
                <div class="cat-no-thumb">no image</div>
            <?php endif; ?>
        </a>
        <small>
            <span title="Replies/Images"><?= $rc ?>/<?= $ic ?></span>
            <span class="act expansionLinks">[<a href="<?= $thread_url ?>" class="history">Expand</a>] [<a href="<?= $thread_url ?>?last=100" class="history">Last 100</a>]</span>
        </small>
        <?php if ($subj): ?><h3>「<?= h($subj) ?>」</h3><?php endif; ?>
        <?php if ($body): ?>
            <div class="cat-snippet"><?= h(mb_substr(strip_tags($body), 0, 200, 'UTF-8')) ?></div>
        <?php endif; ?>
        <?php if ($sticky): ?><div class="cat-sticky-pin">📌 Sticky</div><?php endif; ?>
    </article>
    <?php endforeach; ?>
    <?php if (empty($threads)): ?>
        <div style="color:#888;padding:16px 8px;width:100%;">No threads yet.</div>
    <?php endif; ?>
    </div>

    <hr>
    <aside class="act compact">
        <a href="<?= BASE_URL . h($board_uri) ?>/" class="history">Return</a>
    </aside>
</threads>

<script>
(function () {
    var themeLink   = document.getElementById('theme');
    var themeSelect = document.getElementById('theme-select');
    var baseUrl     = <?= json_encode(BASE_URL) ?>;
    var saved = localStorage.getItem('theme') || 'ocean';
    function applyTheme(t) { if (themeLink) themeLink.href = baseUrl + 'css/themes/' + t + '.css'; }
    applyTheme(saved);
    if (themeSelect) {
        themeSelect.value = saved;
        themeSelect.addEventListener('change', function () {
            localStorage.setItem('theme', this.value);
            applyTheme(this.value);
        });
    }

    var optAnon = document.getElementById('opt-anon');
    if (optAnon) {
        optAnon.checked = localStorage.getItem('opt-anon') === '1';
        optAnon.addEventListener('change', function () { localStorage.setItem('opt-anon', this.checked ? '1' : '0'); });
    }

    var optHide = document.getElementById('opt-hidethumbs');
    if (optHide) {
        optHide.checked = localStorage.getItem('opt-hidethumbs') === '1';
        function applyHide() {
            document.querySelectorAll('#catalog img.expanded, #catalog .cat-no-thumb').forEach(function (el) {
                el.style.display = optHide.checked ? 'none' : '';
            });
        }
        applyHide();
        optHide.addEventListener('change', function () { localStorage.setItem('opt-hidethumbs', this.checked ? '1' : '0'); applyHide(); });
    }

    var modals = [
        { btn: document.getElementById('options'),         panel: document.getElementById('options-panel') },
        { btn: document.getElementById('banner_FAQ'),      panel: document.getElementById('faq-panel') },
        { btn: document.getElementById('banner_identity'), panel: document.getElementById('identity') },
    ];
    function closeAll() { modals.forEach(function (m) { if (m.panel) m.panel.style.display = 'none'; }); }
    modals.forEach(function (m) {
        if (!m.btn || !m.panel) return;
        m.btn.addEventListener('click', function (e) {
            e.preventDefault();
            var open = m.panel.style.display === 'block';
            closeAll();
            if (!open) m.panel.style.display = 'block';
        });
    });
    document.addEventListener('click', function (e) {
        modals.forEach(function (m) {
            if (!m.panel || m.panel.style.display === 'none') return;
            if (!m.panel.contains(e.target) && (!m.btn || !m.btn.contains(e.target)))
                m.panel.style.display = 'none';
        });
    });

    var uploadToggle = document.getElementById('banner-upload-toggle');
    var uploadPanel  = document.getElementById('banner-upload-panel');
    if (uploadToggle && uploadPanel) {
        <?php if ($banner_msg): ?>uploadPanel.style.display = 'block';<?php endif; ?>
        uploadToggle.addEventListener('click', function (e) {
            e.preventDefault();
            uploadPanel.style.display = uploadPanel.style.display === 'none' ? 'block' : 'none';
        });
    }

    <?php if ($banner_msg && str_starts_with($banner_msg, 'ok:')): ?>
    var bannerImg = document.getElementById('board-banner');
    if (bannerImg) { bannerImg.style.display = ''; bannerImg.src = bannerImg.src.split('?')[0] + '?t=' + Date.now(); }
    <?php endif; ?>
})();
</script>
</html>
