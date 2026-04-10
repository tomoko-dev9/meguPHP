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
        /* ══════════════════════════════════════════
           OCEAN THEME — catalog.php
           ══════════════════════════════════════════ */

        /* ── Reset / Base ── */
        * { box-sizing: border-box; }

        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 10pt;
            color: #389EB6;
            background-color: #1e1e1e;
            background-image: url(<?= BASE_URL ?>css/themes/oceanKoi.png);
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-position: right bottom;
            margin: 0;
            padding: 0;
        }

        /* ── Links ── */
        a {
            color: #B5C8FF;
            text-decoration: none;
            border: none;
        }
        a:hover { color: #af005f; }
        a.referenced {
            text-decoration: none;
            border-bottom: 1px dashed currentColor;
        }
        blockquote a { color: #8A8FF7; }

        /* ── Headings ── */
        h1, h2 {
            color: #357EDD;
            text-align: center;
        }
        h1 {
            font-family: "MS PGothic", "MS Pゴシック", Mona, "Hiragino Kaku Gothic Pro", Helvetica, sans-serif;
            font-size: 30px;
            text-shadow: #074854 1px 1px 0;
            letter-spacing: 1px;
            margin-top: 0;
            margin-bottom: 0;
        }
        h2 { font-family: Tahoma; }
        h3 {
            color: #163FAB;
            display: inline;
            font-size: inherit;
        }

        /* ── Misc typography ── */
        b { color: lightblue; }
        b.admin { color: #15CFFA; }
        b.moderator { color: #5f5faf; }
        em {
            color: #12BD7C;
            font-style: normal;
        }
        hr {
            border: none;
            border-top: 1px solid #2e2e2e;
            clear: both;
            margin: 4px 0;
        }
        .email { color: #99225c; text-decoration: underline !important; }
        .omit { color: #626262; line-height: 200%; }

        /* ── Banner bar ── */
        #banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 3px 8px;
            font-size: 9pt;
            background: rgba(46, 46, 46, 0.7);
            border-bottom: 1px solid #262626;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            -webkit-backface-visibility: hidden;
            -webkit-transform: translateZ(0);
            box-shadow: 3px 3px 3px 0 rgba(0,0,0,0.5);
        }
        #navTop {
            flex: 0 0 auto;
            font-weight: bold;
        }
        #navTop a { color: #B5C8FF; }
        #navTop a:hover { color: #af005f; }
        #banner_center {
            flex: 1 1 auto;
            text-align: center;
            color: #389EB6;
        }
        .bfloat-group {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .bfloat { cursor: pointer; font-weight: bold; }
        .bfloat > svg { fill: #64C0E8; width: 1em; height: 1em; vertical-align: middle; }
        .bfloat:hover > svg { fill: #af005f; }
        #sync { color: #626262; font-size: 8.5pt; cursor: auto; }
        #sync.connected { color: #12BD7C; }
        #sync.error { color: #af005f; }
        #onlineCount { color: #626262; font-size: 8.5pt; cursor: auto; }

        /* ── Modals ── */
        .bmodal {
            display: none;
            position: fixed;
            top: 30px;
            right: 0;
            z-index: 200;
            background-color: #262626;
            border-right: 1px solid #212121;
            border-bottom: 1px solid #212121;
            padding: 1em;
            font-size: 9pt;
            color: #389EB6;
            overflow: auto;
            max-height: 80%;
            max-width: 80%;
            -webkit-backface-visibility: hidden;
            box-shadow: 3px 3px 3px 0 rgba(0,0,0,0.5);
        }
        .bmodal b { color: lightblue; display: block; margin-bottom: 8px; }
        .bmodal hr { border-top: 1px solid #99225c; }
        .bmodal label { display: block; margin-bottom: 4px; color: #389EB6; }
        .bmodal select {
            background: #1e1e1e;
            color: #389EB6;
            border: 1px solid #2e2e2e;
            margin-left: 4px;
        }
        #identity { right: 40px; }
        #faq-panel { right: 80px; max-width: 340px; line-height: 1.7; }
        #options-panel { min-width: 200px; }

        /* ── Articles / aside ── */
        article, aside {
            background-color: rgba(28, 29, 34, 0.78);
            border-right: 1px solid #262626;
            border-bottom: 1px solid #262626;
            display: table;
            padding: 4px 10px;
        }
        article {
            margin: 2px;
        }
        article.highlight {
            background-color: rgba(44, 44, 51, 0.95);
            border-color: #21212c;
        }
        aside {
            margin: 1em;
        }
        aside.compact { margin: 0 1em; }
        aside a { color: #B5C8FF; position: relative; z-index: 15; }
        aside a:hover { color: #af005f; }

        /* ── Thread nav ── */
        threads h1 {
            font-size: 1.4em;
            text-align: center;
            margin: 8px 0 2px 0;
        }
        aside.act.compact {
            text-align: center;
            font-size: 9pt;
            display: table;
            margin: 2px auto;
        }
        .act:before { content: "["; }
        .act:after  { content: "]"; }

        /* ── Catalog grid ── */
        #catalog {
            margin: 0 0.85em;
            display: flex;
            flex-wrap: wrap;
            padding: 4px 0;
        }
        #catalog article {
            width: 165px;
            max-height: 320px;
            display: inline-block;
            text-align: center;
            word-wrap: break-word;
            overflow: hidden;
            vertical-align: top;
            padding: 0.6em;
            margin: 0.15em;
            transition: background-color .15s, border-color .15s;
        }
        #catalog article:hover {
            background-color: rgba(44, 44, 51, 0.95);
            border-color: #21212c;
        }
        #catalog article > a {
            display: block;
            text-align: center;
        }
        #catalog article img.expanded {
            display: block;
            max-width: 150px;
            max-height: 150px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin: 0 auto 3px auto;
            background: rgba(28,29,34,0.5);
            border: 1px solid #2e2e2e;
        }
        .cat-no-thumb {
            width: 150px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(28,29,34,0.5);
            border: 1px solid #2e2e2e;
            color: #626262;
            font-size: 8pt;
            margin: 0 auto 3px auto;
        }
        #catalog article small {
            clear: none;
            display: inline-block;
            color: #389EB6;
            font-size: 8pt;
            margin-bottom: 2px;
            line-height: 1.4;
        }
        #catalog article small span:first-child { margin-right: 0.2em; }
        #catalog article small .act.expansionLinks a { color: #B5C8FF; }
        #catalog article small .act.expansionLinks a:hover { color: #af005f; }
        #catalog article h3 {
            font-size: 8.5pt;
            font-weight: bold;
            color: #163FAB;
            margin: 2px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 152px;
            display: block;
        }
        #catalog article .cat-snippet {
            color: #389EB6;
            font-size: 7.5pt;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
            max-width: 152px;
            text-align: left;
        }
        .cat-sticky-pin { color: #357EDD; font-size: 7.5pt; }

        /* ── Top margin spacer ── */
        #headerTopMargin { width: 100%; height: 1.8em; }

        /* ── Options panel tabs ── */
        .option_tab_sel {
            display: inline;
            -webkit-padding-start: 0;
            padding: 0;
        }
        .option_tab_sel li { list-style-type: none; float: left; }
        .option_tab_sel a {
            display: inline-block;
            padding: 7px;
            border-bottom: 1px solid #2e2e2e;
            color: #B5C8FF;
        }
        .option_tab_sel a.tab_sel { color: #8B3FB1; }
        .option_tab_cont {
            padding-top: 10px;
            padding-left: 5px;
            margin-bottom: 0;
        }
        .option_tab_cont li {
            display: none;
            float: left;
            padding-top: 5px;
            width: 100%;
        }
        .option_tab_cont li label { padding-left: 0.2em; color: #389EB6; }
        .option_tab_cont li.tab_sel { display: inline-block; }

        /* ── Banner upload (admin) ── */
        #banner-upload-toggle {
            display: inline-block;
            margin: 4px 8px;
            font-size: 9pt;
            cursor: pointer;
            color: #B5C8FF;
        }
        #banner-upload-toggle:hover { color: #af005f; }
        #banner-upload-panel {
            display: none;
            margin: 4px 8px 8px 8px;
            background: #262626;
            border-right: 1px solid #212121;
            border-bottom: 1px solid #212121;
            padding: 10px 14px;
            font-size: 9pt;
            color: #389EB6;
            max-width: 420px;
        }
        #banner-upload-panel b { color: lightblue; display: block; margin-bottom: 6px; }
        #banner-upload-panel input[type="file"] { color: #389EB6; margin-bottom: 6px; display: block; }
        #banner-upload-panel input[type="submit"] {
            background: #1e1e1e;
            color: #B5C8FF;
            border: 1px solid #2e2e2e;
            padding: 2px 10px;
            cursor: pointer;
            font-size: 9pt;
        }
        #banner-upload-panel input[type="submit"]:hover { background: #262626; color: #af005f; }
        .banner-msg-ok    { color: #12BD7C; margin-top: 6px; font-size: 9pt; }
        .banner-msg-error { color: #af005f; margin-top: 6px; font-size: 9pt; }

        /* ── Hover overlay ── */
        #hover_overlay {
            width: 100%; height: 100%;
            top: 0; right: 0;
            position: fixed;
            pointer-events: none;
            display: flex;
            z-index: 300;
        }
        #hover_overlay > video, #hover_overlay > img {
            max-height: 100%; max-width: 100%;
            margin: auto;
            display: block;
            object-fit: contain;
        }

        /* ── User background ── */
        #user_bg {
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: -100;
        }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            #catalog article { width: calc(50% - 4px); }
            #catalog article img.expanded,
            .cat-no-thumb { max-width: 100%; width: 100%; }
            body { margin: 1px 0; -webkit-text-size-adjust: none; }
            #banner { margin-left: 0; margin-right: 0; }
            #navTop { margin-left: 0; }
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
        <a id="banner_FAQ" class="bfloat" title="Formatting help"
           style="font-style:italic;font-weight:bold;font-size:10pt;color:#64C0E8;line-height:1;">i</a>
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

<!-- FAQ / Formatting modal -->
<div id="faq-panel" class="bmodal">
    <b>Post Formatting</b>
    <table style="border-collapse:collapse;width:100%;">
        <tr><td style="color:#B5C8FF;padding-right:12px;">&gt;text</td><td>Greentext quote</td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">&gt;&gt;123</td><td>Link to post #123</td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">**text**</td><td><b>Bold</b></td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">__text__</td><td><em>Italic</em></td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">[spoiler]text[/spoiler]</td><td><span style="background:#1e1e1e;color:#1e1e1e;">Spoiler</span></td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">#flip</td><td>Coin flip</td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">#2d6</td><td>Roll 2d6 dice</td></tr>
        <tr><td style="color:#B5C8FF;padding-right:12px;">#8ball</td><td>Magic 8-ball</td></tr>
    </table>
    <hr>
    <div style="color:#626262;">Max upload: 30 MB · JPG PNG GIF WEBP</div>
</div>

<!-- Options panel -->
<div id="options-panel" class="bmodal">
    <b>Options</b>
    <ul class="option_tab_sel">
        <li><a data-content="tab-0" class="tab_sel">General</a></li>
        <li><a data-content="tab-1">Style</a></li>
    </ul>
    <ul class="option_tab_cont">
        <li class="tab-0 tab_sel">
            <label><input type="checkbox" id="opt-anon"> Anonymise names</label>
            <label><input type="checkbox" id="opt-reltime"> Relative timestamps</label>
        </li>
        <li class="tab-1">
            <label><input type="checkbox" id="opt-hidethumbs"> Hide thumbnails</label>
            <br>
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
                    <option value="tavern">tavern</option>
                </select>
            </label>
        </li>
    </ul>
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
            <div style="color:#626262;font-size:8pt;margin-bottom:6px;">
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
        <div style="color:#626262;padding:16px 8px;width:100%;">No threads yet.</div>
    <?php endif; ?>
    </div>

    <hr>
    <aside class="act compact">
        <a href="<?= BASE_URL . h($board_uri) ?>/" class="history">Return</a>
    </aside>
</threads>

<script>
(function () {
    /* ── Theme switcher ── */
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

    /* ── Anonymise ── */
    var optAnon = document.getElementById('opt-anon');
    if (optAnon) {
        optAnon.checked = localStorage.getItem('opt-anon') === '1';
        optAnon.addEventListener('change', function () {
            localStorage.setItem('opt-anon', this.checked ? '1' : '0');
        });
    }

    /* ── Hide thumbnails ── */
    var optHide = document.getElementById('opt-hidethumbs');
    if (optHide) {
        optHide.checked = localStorage.getItem('opt-hidethumbs') === '1';
        function applyHide() {
            document.querySelectorAll('#catalog img.expanded, #catalog .cat-no-thumb').forEach(function (el) {
                el.style.display = optHide.checked ? 'none' : '';
            });
        }
        applyHide();
        optHide.addEventListener('change', function () {
            localStorage.setItem('opt-hidethumbs', this.checked ? '1' : '0');
            applyHide();
        });
    }

    /* ── Options tab switching ── */
    document.querySelectorAll('.option_tab_sel a').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            var target = this.getAttribute('data-content');
            document.querySelectorAll('.option_tab_sel a').forEach(function (t) { t.classList.remove('tab_sel'); });
            document.querySelectorAll('.option_tab_cont li').forEach(function (p) { p.classList.remove('tab_sel'); });
            this.classList.add('tab_sel');
            var panel = document.querySelector('.option_tab_cont li.' + target);
            if (panel) panel.classList.add('tab_sel');
        });
    });

    /* ── Modal toggles ── */
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

    /* ── Banner upload panel ── */
    var uploadToggle = document.getElementById('banner-upload-toggle');
    var uploadPanel  = document.getElementById('banner-upload-panel');
    if (uploadToggle && uploadPanel) {
        <?php if ($banner_msg): ?>uploadPanel.style.display = 'block';<?php endif; ?>
        uploadToggle.addEventListener('click', function (e) {
            e.preventDefault();
            uploadPanel.style.display = uploadPanel.style.display === 'none' ? 'block' : 'none';
        });
    }

    /* ── Refresh banner after upload ── */
    <?php if ($banner_msg && str_starts_with($banner_msg, 'ok:')): ?>
    var bannerImg = document.getElementById('board-banner');
    if (bannerImg) {
        bannerImg.style.display = '';
        bannerImg.src = bannerImg.src.split('?')[0] + '?t=' + Date.now();
    }
    <?php endif; ?>
})();
</script>
</html>
