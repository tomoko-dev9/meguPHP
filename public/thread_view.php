<?php
require_once __DIR__ . '/../includes/core.php';

$board_uri = preg_replace('/[^a-z0-9]/', '', $_GET['board'] ?? '');
$thread_id = (int)($_GET['thread'] ?? 0);

$board = get_board($board_uri);
if (!$board) { http_response_code(404); die('Board not found'); }

$op_stmt = db()->prepare("
    SELECT * FROM posts
    WHERE board_uri = ? AND board_post_id = ? AND thread_id IS NULL AND deleted = 0
    LIMIT 1
");
$op_stmt->execute([$board_uri, $thread_id]);
$op = $op_stmt->fetch();
if (!$op) { http_response_code(404); die('Thread not found'); }

$last100 = isset($_GET['last100']);
if ($last100) {
    $rep_stmt = db()->prepare("
        SELECT * FROM (
            SELECT * FROM posts
            WHERE board_uri = ? AND thread_id = ? AND deleted = 0
            ORDER BY created_at DESC LIMIT 100
        ) sub ORDER BY created_at ASC
    ");
} else {
    $rep_stmt = db()->prepare("
        SELECT * FROM posts
        WHERE board_uri = ? AND thread_id = ? AND deleted = 0
        ORDER BY created_at ASC
    ");
}
$rep_stmt->execute([$board_uri, $thread_id]);
$replies = $rep_stmt->fetchAll();

session_write_close();

$locked   = (bool)($op['locked'] ?? false);
$title    = $op['subject'] ? h($op['subject']) . ' — /' . $board_uri . '/' : '/' . $board_uri . '/ — Thread #' . $thread_id;
$weekdays = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];

function tv_date(string $ts, array $wd): string {
    $dt = new DateTime($ts);
    return $dt->format('d M Y') . '(' . ($wd[$dt->format('D')] ?? $dt->format('D')) . ')' . $dt->format('H:i:s');
}

// Build image-search + size + filename caption (matches board_index style)
function tv_file_caption(array $post, string $board_uri): string {
    $full   = UPLOAD_URL . $board_uri . '/' . h($post['file_name']);
    $thumb  = UPLOAD_URL . $board_uri . '/' . h($post['thumb_name'] ?? $post['file_name']);
    $orig   = h($post['file_original'] ?? $post['file_name']);
    $info   = '';
    if (!empty($post['file_size'])) {
        $kb   = round($post['file_size'] / 1024);
        $info = $kb . ' KB';
        if (!empty($post['file_w']) && !empty($post['file_h']))
            $info .= ', ' . $post['file_w'] . 'x' . $post['file_h'];
    }
    $md5enc = base64_encode(hex2bin(md5($post['file_name'])));
    return
        '<a target="_blank" rel="nofollow" class="imageSearch google"      href="https://www.google.com/searchbyimage?image_url=' . urlencode($thumb) . '">G</a>'
      . '<a target="_blank" rel="nofollow" class="imageSearch iqdb"        href="http://iqdb.org/?url=' . urlencode($thumb) . '">Iq</a>'
      . '<a target="_blank" rel="nofollow" class="imageSearch saucenao"    href="http://saucenao.com/search.php?db=999&url=' . urlencode($thumb) . '">Sn</a>'
      . '<a target="_blank" rel="nofollow" class="imageSearch desustorage" href="https://desuarchive.org/_/search/image/' . urlencode($md5enc) . '">Ds</a>'
      . ' <i>(' . $info . ') <a href="' . $full . '" rel="nofollow" download="' . $orig . '">' . $orig . '</a></i>';
}

// OP file: figcaption above, image below, floats left
function tv_file_op(array $post, string $board_uri): string {
    if (empty($post['file_name'])) return '';
    $full  = UPLOAD_URL . $board_uri . '/' . h($post['file_name']);
    $thumb = UPLOAD_URL . $board_uri . '/' . h($post['thumb_name'] ?? $post['file_name']);
    $tw    = min((int)($post['thumb_w'] ?? 200), 200);
    $th    = min((int)($post['thumb_h'] ?? 200), 200);
    return '<figure class="op-file">'
         . '<figcaption>' . tv_file_caption($post, $board_uri) . '</figcaption>'
         . '<a target="_blank" rel="nofollow" href="' . $full . '">'
         . '<img src="' . $thumb . '" width="' . $tw . '" height="' . $th . '" loading="lazy">'
         . '</a>'
         . '</figure>';
}

// Reply file info — rendered ABOVE the article box (outside it)
function tv_reply_fileinfo(array $post, string $board_uri): string {
    if (empty($post['file_name'])) return '';
    return '<div class="reply-fileinfo">' . tv_file_caption($post, $board_uri) . '</div>';
}

// Reply thumbnail — floats left INSIDE the article box, no caption
function tv_reply_thumb(array $post, string $board_uri): string {
    if (empty($post['file_name'])) return '';
    $full  = UPLOAD_URL . $board_uri . '/' . h($post['file_name']);
    $thumb = UPLOAD_URL . $board_uri . '/' . h($post['thumb_name'] ?? $post['file_name']);
    $tw    = min((int)($post['thumb_w'] ?? 125), 125);
    $th    = min((int)($post['thumb_h'] ?? 125), 125);
    return '<figure class="reply-file">'
         . '<a target="_blank" rel="nofollow" href="' . $full . '">'
         . '<img src="' . $thumb . '" width="' . $tw . '" height="' . $th . '" loading="lazy">'
         . '</a>'
         . '</figure>';
}
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $title ?></title>
    <meta name="viewport" content="width=device-width, minimum-scale=1.0, maximum-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
    <link rel="stylesheet" id="theme">
    <style id="backgroundCSS"></style>
    <style>
    *, *::before, *::after { box-sizing: border-box; }

    .themed, body, pre, textarea, input, select {
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    }
    body {
        color: #389eb6;
        background-color: #1e1e1e;
        background-image: url(<?= BASE_URL ?>public/css/oceanKoi.png);
        background-repeat: no-repeat;
        background-attachment: fixed;
        background-position: right bottom;
        font-size: 10pt;
        margin: 0; padding: 0;
    }
    a { color: #b5c8ff; }
    a:hover { color: #af005f; }
    b { color: lightblue; }
    b.admin { color: #15cffa; }
    b.moderator { color: #5f5faf; }
    em { color: #12bd7c; }
    h1 {
        font-family: "MS PGothic", Mona, "Hiragino Kaku Gothic Pro", Helvetica, sans-serif;
        font-size: 30px; text-shadow: #074854 1px 1px 0;
        letter-spacing: 1px; color: #357edd; margin: 0;
    }
    hr { border: none; border-top: 1px solid #2e2e2e; margin: 6px 0; }
    pre { color: #8a8a8a; font-size: 10pt; }
    .spoiler { background: #000; color: #000; }
    .spoiler:hover { color: #fff; }
    :target { outline: 2px solid #f0a000; outline-offset: 2px; }
    .new-post-flash { animation: npflash 1.5s ease-out; }
    @keyframes npflash {
        0%   { background-color: rgba(255,255,170,0.25); }
        100% { background-color: transparent; }
    }

    /* ── Banner ── */
    #banner {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; font-size: 9pt;
        background: rgba(46,46,46,0.85); border-bottom: 1px solid #2a2a2a;
        padding: 2px 6px;
    }
    #navTop { flex: 0 0 auto; }
    #banner_center { flex: 1 1 auto; text-align: center; color: #389eb6; }
    #banner .bfloat-group { flex: 0 0 auto; display: flex; align-items: center; gap: 6px; }
    #sync { font-weight: bold; color: #626262; font-size: 9pt; }
    #sync.connected { color: #12bd7c; }
    #sync.error { color: #af005f; }
    .bfloat { cursor: pointer; }
    .bfloat > svg { fill: #64c0e8; vertical-align: middle; }

    /* ── Board header ── */
    #board-header { text-align: center; margin: 6px 0 2px 0; }
    #board-header img { display: block; margin: 0 auto 4px auto; max-width: 400px; }
    #board-header h1 { font-size: 2em; font-weight: bold; margin: 0; }

    /* ── Thread nav — matches board_index pagination style ── */
    .thread-nav-wrap {
        margin: 4px 8px;
        font-size: 9pt;
        color: #389eb6;
    }
    .thread-nav-wrap a { color: #b5c8ff; text-decoration: none; margin: 0 3px; }
    .thread-nav-wrap a:hover { color: #af005f; }
    .thread-nav-wrap strong { color: #389eb6; font-weight: normal; margin: 0 3px; }

    /* ── Checkboxes ── */
    input.postCheckbox {
        display: inline !important; visibility: visible !important; opacity: 1 !important;
        position: static !important; width: 13px !important; height: 13px !important;
        -webkit-appearance: checkbox !important; appearance: checkbox !important;
        margin: 0 3px 0 0 !important; padding: 0 !important;
        vertical-align: middle; flex-shrink: 0;
    }

    /* ══════════════════════════════════════════════════════
       IMAGE SEARCH LINKS
       Only "G" visible by default; others toggled via JS/body class
    ══════════════════════════════════════════════════════ */
    a.imageSearch       { font-size: 8pt; color: #64c0e8; text-decoration: none; margin-right: 1px; }
    a.imageSearch:hover { color: #af005f; }
    a.imageSearch.iqdb,
    a.imageSearch.saucenao,
    a.imageSearch.desustorage { display: none; }
    body.show-iqdb        a.imageSearch.iqdb        { display: inline; }
    body.show-saucenao    a.imageSearch.saucenao    { display: inline; }
    body.show-desustorage a.imageSearch.desustorage { display: inline; }

    /* ══════════════════════════════════════════════════════
       OP FILE — figcaption above image, floats left
    ══════════════════════════════════════════════════════ */
    figure.op-file {
        float: left;
        clear: none;
        margin: 0 14px 8px 0;
        max-width: 200px;
    }
    figure.op-file figcaption {
        font-size: 8pt; color: #8a8a8a;
        margin-bottom: 3px; line-height: 1.5; white-space: nowrap;
    }
    figure.op-file figcaption i { font-style: normal; color: #8a8a8a; }
    figure.op-file figcaption i a { color: #b5c8ff; text-decoration: none; }
    figure.op-file figcaption i a:hover { color: #af005f; }
    figure.op-file > a img {
        display: block; max-width: 200px; max-height: 200px; cursor: pointer;
    }

    /* ══════════════════════════════════════════════════════
       OP BLOCK — float container
    ══════════════════════════════════════════════════════ */
    .op-wrap {
        display: block;
        margin: 6px 8px;
        overflow: hidden;
    }
    .op-wrap > .op-body { overflow: hidden; }

    .op-header {
        font-size: 9pt; line-height: 1.9; margin: 0 0 2px 0; padding: 0;
        display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 4px;
    }
    .op-header .subj { color: #163fab; font-weight: bold; font-size: 9pt; }
    .op-header .name { color: #add8e6; font-weight: bold; }
    .op-header .trip { color: #64c0e8; }
    .op-header time  { color: #389eb6; font-size: 10pt; }

    .op-body > blockquote {
        margin: 4px 0 0 0;
        word-break: break-word;
        color: #389eb6; line-height: 1.5;
    }

    /* ══════════════════════════════════════════════════════
       REPLY FILE INFO — OUTSIDE/ABOVE the article box
    ══════════════════════════════════════════════════════ */
    .reply-fileinfo {
        font-size: 8pt; color: #8a8a8a;
        margin: 4px 0 0 26px;
        line-height: 1.5; white-space: nowrap; clear: both;
    }
    .reply-fileinfo i { font-style: normal; color: #8a8a8a; }
    .reply-fileinfo i a { color: #b5c8ff; text-decoration: none; }
    .reply-fileinfo i a:hover { color: #af005f; }

    /* ══════════════════════════════════════════════════════
       REPLY LIST
    ══════════════════════════════════════════════════════ */
    #reply-list {
        clear: both;
        margin: 4px 8px 0 8px;
    }

    #reply-list article {
        display: block;
        overflow: hidden;
        background: rgba(28,29,34,0.82);
        border: 1px solid #252525;
        margin: 2px 0 2px 18px;
        padding: 3px 6px 4px 6px;
        width: fit-content;
        max-width: calc(100% - 18px);
    }
    article.highlight { background-color: rgba(44,44,51,0.95) !important; }

    .reply-header {
        font-size: 9pt; line-height: 1.9; margin: 0 0 1px 0; padding: 0;
        display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 4px;
    }
    .reply-header .name { color: #add8e6; font-weight: bold; }
    .reply-header .trip { color: #64c0e8; }
    .reply-header time  { color: #389eb6; font-size: 10pt; }

    /* Reply thumbnail — floats left INSIDE article */
    figure.reply-file {
        float: left; clear: none;
        margin: 2px 8px 4px 0;
        max-width: 125px;
    }
    figure.reply-file > a img {
        display: block; max-width: 125px; max-height: 125px; cursor: pointer;
    }

    /* Reply body — BFC beside floated thumb */
    #reply-list article > blockquote {
        overflow: hidden;
        margin: 2px 0 0 0;
        word-break: break-word;
        color: #389eb6; line-height: 1.5;
    }

    /* Backlinks */
    .backlinks { font-size: 8pt; color: #626262; margin: 2px 0 0 0; clear: both; }
    .backlinks a { color: #8a8ff7; text-decoration: none; margin-right: 3px; }
    .backlinks a:hover { color: #af005f; }

    /* ══════════════════════════════════════════════════════
       TEXT COLOURS
    ══════════════════════════════════════════════════════ */
    .quote-text { color: #789922; }
    .post-ref   { color: #8a8ff7; text-decoration: none; }
    .post-ref:hover { color: #af005f; }
    blockquote a { color: #8a8ff7; }
    .act-del a  { color: #af005f; font-size: 8pt; text-decoration: none; margin-left: 4px; }
    .act-del a:hover { text-decoration: underline; }
    nav.post-nav a { color: #b5c8ff; text-decoration: none; font-size: 10pt; }
    nav.post-nav a:hover { color: #af005f; }

    /* ── Reply form ── */
    aside.act.posting {
        display: block; clear: both;
        font-size: 9pt; margin: 6px 8px;
        background: transparent; border: none; padding: 0;
    }
    aside.act.posting a { color: #b5c8ff; text-decoration: none; }
    aside.act.posting a:hover { color: #af005f; }

    section.post-form-section {
        display: block; margin: 0 8px 4px 8px; padding: 4px 6px;
    }
    section.post-form-section blockquote { margin: 2px 0 4px 0; }
    section.post-form-section blockquote p { margin: 0; padding: 0; }

    textarea#trans {
        display: block; resize: vertical; min-height: 80px; width: 300px;
        border: 0; padding: 0; background: transparent;
        color: #8a8a8a; outline: none;
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
        font-size: 10pt;
    }
    form.post-bottom { display: inline; margin: 0; }
    form.post-bottom input[type="button"],
    form.post-bottom input[type="submit"] { font-size: 9pt; cursor: pointer; margin-right: 4px; }
    input#toggle {
        background: url('<?= BASE_URL ?>public/css/ui/pane.png') transparent no-repeat center center;
        background-size: 78px 19px; border: 0; height: 19px; width: 78px;
        cursor: pointer; vertical-align: middle;
    }
    #form-status { font-size: 8pt; color: #af005f; }

    /* ── Hover preview ── */
    #post-preview {
        display: none; position: fixed; z-index: 12000;
        max-width: 380px; min-width: 160px;
        background: rgba(20,21,26,0.97); border: 1px solid #389eb6;
        padding: 6px 10px 8px 10px; pointer-events: none;
        box-shadow: 0 4px 18px rgba(0,0,0,0.75); font-size: 9pt; color: #389eb6;
    }
    #post-preview .pv-header { font-size: 8pt; margin-bottom: 4px; color: #8a8a8a; line-height: 1.6; }
    #post-preview .pv-header .name { color: #add8e6; font-weight: bold; }
    #post-preview .pv-header time  { color: #389eb6; }
    #post-preview .pv-header .post-no { color: #b5c8ff; }
    #post-preview .pv-thumb { float: left; margin: 0 8px 4px 0; }
    #post-preview .pv-thumb img { max-width: 80px; max-height: 80px; display: block; }
    #post-preview .pv-body {
        overflow: hidden; word-break: break-word;
        color: #389eb6; line-height: 1.5; max-height: 200px; overflow-y: auto;
    }
    #post-preview .pv-body p { margin: 0; }
    #post-preview .pv-body .quote-text { color: #789922; }
    #post-preview .pv-body a { color: #8a8ff7; text-decoration: none; }
    #post-preview::after { content: ""; display: table; clear: both; }

    .bmodal {
        background-color: #262626;
        border-right: 1px solid #212121;
        border-bottom: 1px solid #212121;
    }
    .bmodal hr { border-top: 1px solid #99225c; }

    @media (max-width: 600px) {
        figure.op-file { max-width: 140px; }
        figure.op-file > a img { max-width: 140px; max-height: 140px; }
        #reply-list article { margin-left: 4px; }
        figure.reply-file { max-width: 100px; }
        figure.reply-file > a img { max-width: 100px; max-height: 100px; }
    }
    </style>
</head>
<body>

<fieldset id="identity" class="bmodal" style="display:none;position:fixed;top:30px;right:40px;z-index:9999;">
    <label>Name:</label> <input id="name" name="name" value="Anonymous"><br>
    <label>Email:</label> <input id="email" name="email"><br>
</fieldset>
<div id="post-preview"></div>

<!-- ── Banner ── -->
<span id="banner">
    <b id="navTop"><?= render_nav($board_uri) ?></b>
    <b id="banner_center"></b>
    <span class="bfloat-group">
        <b id="sync" class="bfloat">Not synched</b>
        <a id="feedback" href="mailto:<?= h(SITE_EMAIL) ?>" target="_blank" class="bfloat" title="Feedback">
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8"><path d="M0 0v1l4 2 4-2v-1h-8zm0 2v4h8v-4l-4 2-4-2z" transform="translate(0 1)"/></svg>
        </a>
        <a id="banner_FAQ" class="bfloat" title="Formatting help" style="cursor:pointer;font-style:italic;font-weight:bold;font-size:10pt;color:#64c0e8;text-decoration:none;line-height:1;">i</a>
        <a id="banner_identity" class="bfloat" title="Identity" style="cursor:pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8"><path d="M4 0c-1.1 0-2 1.12-2 2.5s.9 2.5 2 2.5 2-1.12 2-2.5-.9-2.5-2-2.5zm-2.09 5c-1.06.05-1.91.92-1.91 2v1h8v-1c0-1.08-.84-1.95-1.91-2-.54.61-1.28 1-2.09 1-.81 0-1.55-.39-2.09-1z"/></svg>
        </a>
        <a id="options" class="bfloat" title="Options" style="cursor:pointer;">
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8"><path d="M5.5 0c-1.38 0-2.5 1.12-2.5 2.5 0 .32.08.62.19.91l-2.91 2.88c-.39.39-.39 1.05 0 1.44.2.2.46.28.72.28.26 0 .52-.09.72-.28l2.88-2.91c.28.11.58.19.91.19 1.38 0 2.5-1.12 2.5-2.5 0-.16 0-.32-.03-.47l-.97.97h-2v-2l.97-.97c-.15-.03-.31-.03-.47-.03zm-4.5 6.5c.28 0 .5.22.5.5s-.22.5-.5.5-.5-.22-.5-.5.22-.5.5-.5z"/></svg>
        </a>
    </span>
</span>

<div id="faq-panel" style="display:none;position:fixed;top:30px;right:80px;z-index:9999;background:#262626;border:1px solid #212121;padding:12px 16px;font-size:9pt;color:#389eb6;max-width:340px;line-height:1.7;">
    <b style="color:#b5c8ff;display:block;margin-bottom:6px;">Post Formatting</b>
    <table style="border-collapse:collapse;width:100%;">
        <tr><td style="color:#add8e6;padding-right:12px;">&gt;text</td><td>Greentext</td></tr>
        <tr><td style="color:#add8e6;padding-right:12px;">&gt;&gt;123</td><td>Link to post</td></tr>
        <tr><td style="color:#add8e6;padding-right:12px;">**text**</td><td><b>Bold</b></td></tr>
        <tr><td style="color:#add8e6;padding-right:12px;">__text__</td><td><em>Italic</em></td></tr>
        <tr><td style="color:#add8e6;padding-right:12px;">[spoiler]…[/spoiler]</td><td><span class="spoiler" style="color:#fff">Spoiler</span></td></tr>
        <tr><td style="color:#add8e6;padding-right:12px;">#flip / #2d6 / #8ball</td><td>Random</td></tr>
    </table>
    <hr style="border-top:1px solid #99225c;margin:8px 0;">
    <div style="color:#626262;">Max upload: 30 MB · JPG PNG GIF WEBP</div>
</div>

<div id="options-panel" style="display:none;position:fixed;top:30px;right:8px;z-index:9999;background:#262626;border:1px solid #212121;padding:10px 14px;font-size:9pt;color:#389eb6;min-width:220px;">
    <b style="color:#b5c8ff;display:block;margin-bottom:8px;">Options</b>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-anon"> Anonymise names</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-rtime"> Relative timestamps</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-hidethumbs"> Hide thumbnails</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-lock"> Lock scroll to bottom</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-previews" checked> Hover previews</label>
    <hr style="border-top:1px solid #99225c;margin:8px 0;">
    <b style="color:#b5c8ff;display:block;margin-bottom:4px;">Image Search</b>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-google" checked> Google</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-iqdb"> Iqdb</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-saucenao"> Saucenao</label>
    <label style="display:block;margin-bottom:4px;"><input type="checkbox" id="opt-desustorage"> Desustorage</label>
    <hr style="border-top:1px solid #99225c;margin:8px 0;">
    <label style="display:block;margin-bottom:4px;">Theme:
        <select id="theme-select" style="background:#1e1e1e;color:#b5c8ff;border:1px solid #262626;margin-left:4px;">
            <option value="ocean">ocean</option><option value="moe">moe</option><option value="gar">gar</option>
            <option value="moon">moon</option><option value="ashita">ashita</option><option value="console">console</option>
            <option value="tea">tea</option><option value="higan">higan</option><option value="rave">rave</option>
        </select>
    </label>
</div>

<div id="headerTopMargin"></div>

<div id="board-header">
    <img src="<?= BASE_URL ?>banners/banner-<?= h($board_uri) ?>.png" onerror="this.style.display='none'" alt="">
    <h1>/<?= h($board_uri) ?>/<?php if (!empty($board['title'])): ?> - <?= h($board['title']) ?><?php endif; ?></h1>
</div>

<!-- Thread nav — styled like pagination bar -->
<div class="thread-nav-wrap">
    [<a href="<?= BASE_URL . h($board_uri) ?>/">Return</a>]
    [<a href="<?= BASE_URL . h($board_uri) ?>/catalog">Catalog</a>]
    <?php if (!$last100): ?>[<a href="?last100">Last 100</a>]<?php endif; ?>
</div>
<hr>

<!-- ══════════════════════════════════════════
     OP POST
══════════════════════════════════════════ -->
<div class="op-wrap" id="thread-<?= $thread_id ?>">
    <?= tv_file_op($op, $board_uri) ?>
    <div class="op-body">
        <div class="op-header">
            <input type="checkbox" class="postCheckbox">
            <?php if (!empty($op['subject'])): ?><span class="subj">「<?= h($op['subject']) ?>」</span><?php endif; ?>
            <b class="name"><?= h($op['name'] ?: 'Anonymous') ?></b><?= capcode_html($op['capcode'] ?? null) ?>
            <?php if (!empty($op['tripcode'])): ?><code class="trip"><?= h($op['tripcode']) ?></code><?php endif; ?>
            <?php if (!empty($op['sticky'])): ?>📌<?php endif; ?>
            <?php if (!empty($op['locked'])): ?>🔒<?php endif; ?>
            <time datetime="<?= (new DateTime($op['created_at']))->format('c') ?>"><?= tv_date($op['created_at'], $weekdays) ?></time>
            <nav class="post-nav">
                <a href="#p<?= $thread_id ?>" class="quote" data-pid="<?= $thread_id ?>">No.</a><a href="#p<?= $thread_id ?>"><?= $thread_id ?></a>
            </nav>
            <?php if (is_admin()): ?>
            <span class="act-del"><a href="<?= BASE_URL ?>admin/delete.php?board=<?= urlencode($board_uri) ?>&post=<?= $thread_id ?>" onclick="return confirm('Delete OP and whole thread?')">Del</a></span>
            <?php endif; ?>
        </div>
        <blockquote id="p<?= $thread_id ?>"
            data-name="<?= h($op['name'] ?: 'Anonymous') ?>"
            data-time="<?= tv_date($op['created_at'], $weekdays) ?>"
            data-pid="<?= $thread_id ?>"
            data-thumb="<?= !empty($op['thumb_name']) ? h(UPLOAD_URL . $board_uri . '/' . $op['thumb_name']) : '' ?>"
        ><?= format_post($op['body'], $board_uri) ?></blockquote>
    </div>
</div>

<!-- ══════════════════════════════════════════
     REPLIES
══════════════════════════════════════════ -->
<div id="reply-list">
<?php foreach ($replies as $r):
    $rid   = (int)$r['board_post_id'];
    $rname = h($r['name'] ?: 'Anonymous');
    $rtrip = h($r['tripcode'] ?? '');
    $riso  = (new DateTime($r['created_at']))->format('c');
    $rdate = tv_date($r['created_at'], $weekdays);
?>
<?= tv_reply_fileinfo($r, $board_uri) ?>
<article id="p<?= $rid ?>">
    <div class="reply-header">
        <input type="checkbox" class="postCheckbox">
        <b class="name"><?= $rname ?></b>
        <?php if ($rtrip): ?><code class="trip"><?= $rtrip ?></code><?php endif; ?><?= capcode_html($r['capcode'] ?? null) ?>
        <time datetime="<?= $riso ?>"><?= $rdate ?></time>
        <nav class="post-nav">
            <a href="#p<?= $rid ?>" class="quote" data-pid="<?= $rid ?>">No.</a><a href="#p<?= $rid ?>"><?= $rid ?></a>
        </nav>
        <?php if (is_admin()): ?>
        <span class="act-del"><a href="<?= BASE_URL ?>admin/delete.php?board=<?= urlencode($board_uri) ?>&post=<?= $rid ?>" onclick="return confirm('Delete?')">Del</a></span>
        <?php endif; ?>
    </div>
    <?= tv_reply_thumb($r, $board_uri) ?>
    <blockquote
        data-name="<?= $rname ?>"
        data-time="<?= $rdate ?>"
        data-pid="<?= $rid ?>"
        data-thumb="<?= !empty($r['thumb_name']) ? h(UPLOAD_URL . $board_uri . '/' . $r['thumb_name']) : '' ?>"
    ><?= format_post($r['body'], $board_uri) ?></blockquote>
    <div class="backlinks" id="bl-<?= $rid ?>"></div>
</article>
<?php endforeach; ?>
</div><!-- /#reply-list -->

<?php if (!$locked || is_admin()): ?>
<aside class="act posting"><a href="#" id="bottom-reply">[Reply]</a></aside>

<section class="post-form-section" id="reply-form-section" style="display:none;">
    <blockquote>
        <textarea name="body" id="trans" rows="4" autocomplete="off" placeholder="Reply…"></textarea>
    </blockquote>
    <form class="post-bottom" method="post"
          action="<?= BASE_URL . h($board_uri) ?>/post.php"
          enctype="multipart/form-data"
          id="reply-form">
        <input type="hidden" name="_board"    value="<?= h($board_uri) ?>">
        <input type="hidden" name="thread_id" value="<?= $thread_id ?>">
        <input type="hidden" name="name"      id="pf-name">
        <input type="hidden" name="email"     id="pf-email">
        <input type="hidden" name="body"      id="pf-body">
        <input type="button" value="Cancel"   id="form-cancel">
        <input type="file"   name="image"     accept="image/jpeg,image/png,image/gif,image/webp">
        <input type="button" id="toggle"      title="Post">
        <strong id="form-status"></strong>
    </form>
</section>
<?php elseif ($locked): ?>
<p style="color:#666;font-size:9pt;margin:6px 8px;">🔒 This thread is locked.</p>
<?php endif; ?>

<div class="thread-nav-wrap" style="margin-top:8px;">
    [<a href="<?= BASE_URL . h($board_uri) ?>/">Return</a>]
    [<a href="<?= BASE_URL . h($board_uri) ?>/catalog">Catalog</a>]
</div>

<script>
(function () {
    var syncEl    = document.getElementById('sync');
    var board     = <?= json_encode($board_uri) ?>;
    var threadId  = <?= (int)$thread_id ?>;
    var baseUrl   = <?= json_encode(BASE_URL) ?>;
    var lastId    = 0;
    var unread    = 0;
    var origTitle = document.title;

    /* ── Reply form ── */
    var formSection = document.getElementById('reply-form-section');
    var formCancel  = document.getElementById('form-cancel');
    var toggleBtn   = document.getElementById('toggle');
    var transTA     = document.getElementById('trans');
    var pfName      = document.getElementById('pf-name');
    var pfEmail     = document.getElementById('pf-email');
    var pfBody      = document.getElementById('pf-body');
    var replyForm   = document.getElementById('reply-form');
    var identName   = document.getElementById('name');
    var identEmail  = document.getElementById('email');
    var formStatus  = document.getElementById('form-status');
    var bottomReply = document.getElementById('bottom-reply');
    var formOpen    = false;

    function openForm(quotePid) {
        if (formSection) formSection.style.display = 'block';
        formOpen = true;
        if (quotePid && transTA) transTA.value += '>>' + quotePid + '\n';
        if (formSection) formSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        if (transTA) transTA.focus();
    }
    function closeForm() {
        if (formSection) formSection.style.display = 'none';
        if (transTA) { transTA.value = ''; transTA.style.height = ''; }
        formOpen = false;
    }

    if (formCancel)  formCancel.addEventListener('click',  closeForm);
    if (bottomReply) bottomReply.addEventListener('click', function (e) { e.preventDefault(); openForm(null); });

    document.addEventListener('click', function (e) {
        var q = e.target.closest('a.quote');
        if (q && q.dataset.pid) { e.preventDefault(); openForm(q.dataset.pid); }
    });

    if (toggleBtn) toggleBtn.addEventListener('click', function () {
        if (!transTA || !transTA.value.trim()) { if (formStatus) formStatus.textContent = 'Comment required.'; return; }
        pfName.value  = identName  ? identName.value  : 'Anonymous';
        pfEmail.value = identEmail ? identEmail.value : '';
        pfBody.value  = transTA.value;
        replyForm.submit();
    });
    if (transTA) transTA.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.max(80, this.scrollHeight) + 'px';
    });

    /* ── Image expand in-place ── */
    document.addEventListener('click', function (e) {
        var link = e.target.closest('figure > a[rel="nofollow"]'); if (!link) return;
        var img  = link.querySelector('img'); if (!img) return;
        e.preventDefault();
        if (link.getAttribute('data-expanded') === '1') {
            img.src = img.getAttribute('data-thumb') || img.src;
            img.style.maxWidth = img.style.maxHeight = '';
            img.style.width = img.style.height = '';
            link.setAttribute('data-expanded', '0');
        } else {
            img.setAttribute('data-thumb', img.src);
            img.src = link.href;
            img.style.maxWidth = '100%'; img.style.maxHeight = 'none';
            img.style.width = img.style.height = 'auto';
            link.setAttribute('data-expanded', '1');
        }
    });

    /* ── Hover preview ── */
    var preview    = document.getElementById('post-preview');
    var previewTmr = null;

    function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function buildPreview(pid) {
        var bq = document.querySelector('blockquote[data-pid="' + pid + '"]'); if (!bq) return null;
        var name  = bq.getAttribute('data-name')  || 'Anonymous';
        var time  = bq.getAttribute('data-time')  || '';
        var thumb = bq.getAttribute('data-thumb') || '';
        var html  = '';
        if (thumb) html += '<div class="pv-thumb"><img src="' + thumb + '" alt=""></div>';
        html += '<div class="pv-header"><span class="name">' + escHtml(name) + '</span> <time>' + escHtml(time) + '</time> <span class="post-no">No.' + pid + '</span></div>';
        html += '<div class="pv-body">' + bq.innerHTML + '</div>';
        return html;
    }
    function showPreview(pid, x, y) {
        var optPrev = document.getElementById('opt-previews');
        if (optPrev && !optPrev.checked) return;
        var html = buildPreview(pid); if (!html) return;
        preview.innerHTML = html; preview.style.display = 'block'; positionPreview(x, y);
    }
    function positionPreview(x, y) {
        preview.style.visibility = 'hidden'; preview.style.display = 'block';
        var pw = preview.offsetWidth || 320, ph = preview.offsetHeight || 80;
        preview.style.visibility = '';
        var left = x + 18, top = y + 14;
        if (left + pw > window.innerWidth  - 8) left = x - pw - 8;
        if (top  + ph > window.innerHeight - 8) top  = window.innerHeight - ph - 8;
        preview.style.left = Math.max(4, left) + 'px';
        preview.style.top  = Math.max(4, top)  + 'px';
    }
    function hidePreview() { preview.style.display = 'none'; preview.innerHTML = ''; }

    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest('a.post-ref'); if (!a) return;
        var m = a.href.match(/#p(\d+)$/); if (!m) return;
        clearTimeout(previewTmr);
        previewTmr = setTimeout(function () { showPreview(m[1], e.clientX, e.clientY); }, 100);
    });
    document.addEventListener('mousemove', function (e) { if (preview.style.display === 'block') positionPreview(e.clientX, e.clientY); });
    document.addEventListener('mouseout',  function (e) { if (!e.target.closest('a.post-ref')) return; clearTimeout(previewTmr); hidePreview(); });

    /* ── Backlinks ── */
    function addBacklink(toPid, fromPid) {
        var bl = document.getElementById('bl-' + toPid);
        if (!bl || bl.querySelector('[data-from="' + fromPid + '"]')) return;
        var a = document.createElement('a');
        a.href = '#p' + fromPid; a.className = 'post-ref';
        a.dataset.from = fromPid; a.textContent = '>>' + fromPid;
        bl.appendChild(a);
        bl.appendChild(document.createTextNode(' '));
    }
    document.querySelectorAll('#reply-list article blockquote, .op-body blockquote').forEach(function (bq) {
        var art     = bq.closest('article');
        var fromPid = art ? art.id.replace('p', '') : null;
        if (!fromPid) return;
        bq.querySelectorAll('a.post-ref').forEach(function (a) {
            var m = a.href.match(/#p(\d+)$/);
            if (m) addBacklink(m[1], fromPid);
        });
    });

    /* ── Options / FAQ / Identity panels ── */
    var optBtn   = document.getElementById('options'),         optPanel  = document.getElementById('options-panel');
    var faqBtn   = document.getElementById('banner_FAQ'),      faqPanel  = document.getElementById('faq-panel');
    var identBtn = document.getElementById('banner_identity'), identBox  = document.getElementById('identity');
    function closeAll() { [optPanel, faqPanel, identBox].forEach(function (p) { if (p) p.style.display = 'none'; }); }
    if (optBtn)   optBtn.addEventListener('click',   function (e) { e.preventDefault(); var o = optPanel.style.display !== 'none'; closeAll(); if (!o) optPanel.style.display = 'block'; });
    if (faqBtn)   faqBtn.addEventListener('click',   function (e) { e.preventDefault(); var o = faqPanel.style.display !== 'none'; closeAll(); if (!o) faqPanel.style.display = 'block'; });
    if (identBtn) identBtn.addEventListener('click', function (e) { e.preventDefault(); var o = identBox.style.display !== 'none'; closeAll(); if (!o) identBox.style.display = 'block'; });
    document.addEventListener('click', function (e) {
        [optPanel, faqPanel, identBox].forEach(function (panel, i) {
            var btn = [optBtn, faqBtn, identBtn][i];
            if (panel && panel.style.display !== 'none' && !panel.contains(e.target) && btn && !btn.contains(e.target))
                panel.style.display = 'none';
        });
    });

    /* ── Theme ── */
    var themeLink   = document.getElementById('theme');
    var themeSelect = document.getElementById('theme-select');
    var savedTheme  = localStorage.getItem('theme') || 'ocean';
    function applyTheme(t) { if (themeLink) themeLink.href = baseUrl + 'public/css/themes/' + t + '.css'; }
    applyTheme(savedTheme);
    if (themeSelect) {
        themeSelect.value = savedTheme;
        themeSelect.addEventListener('change', function () { localStorage.setItem('theme', this.value); applyTheme(this.value); });
    }

    /* ── Image search link visibility ── */
    var searchLinks = ['google', 'iqdb', 'saucenao', 'desustorage'];
    var googleStyle = document.createElement('style');
    document.head.appendChild(googleStyle);
    searchLinks.forEach(function (name) {
        var el = document.getElementById('opt-' + name); if (!el) return;
        var stored = localStorage.getItem('opt-search-' + name);
        el.checked = stored !== null ? stored === '1' : (name === 'google');
        function apply() {
            if (name === 'google') {
                googleStyle.textContent = !el.checked ? 'a.imageSearch.google { display:none; }' : '';
            } else {
                if (el.checked) document.body.classList.add('show-' + name);
                else             document.body.classList.remove('show-' + name);
            }
        }
        apply();
        el.addEventListener('change', function () { localStorage.setItem('opt-search-' + name, this.checked ? '1' : '0'); apply(); });
    });

    /* ── Anonymise ── */
    var optAnon = document.getElementById('opt-anon');
    if (optAnon) {
        optAnon.checked = localStorage.getItem('opt-anon') === '1';
        function applyAnon() {
            document.querySelectorAll('b.name').forEach(function (el) {
                if (optAnon.checked) { el.setAttribute('data-real', el.textContent); el.textContent = 'Anonymous'; }
                else if (el.getAttribute('data-real')) el.textContent = el.getAttribute('data-real');
            });
        }
        applyAnon();
        optAnon.addEventListener('change', function () { localStorage.setItem('opt-anon', this.checked ? '1' : '0'); applyAnon(); });
    }

    /* ── Relative timestamps ── */
    var optRtime = document.getElementById('opt-rtime');
    function relTime(d) {
        var s = Math.floor((Date.now() - d.getTime()) / 1000);
        if (s < 60)    return s + 's ago';
        if (s < 3600)  return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }
    if (optRtime) {
        optRtime.checked = localStorage.getItem('opt-rtime') === '1';
        function applyRtime() {
            document.querySelectorAll('time[datetime]').forEach(function (el) {
                if (!el.getAttribute('data-abs')) el.setAttribute('data-abs', el.textContent);
                el.textContent = optRtime.checked ? relTime(new Date(el.getAttribute('datetime'))) : el.getAttribute('data-abs');
            });
        }
        applyRtime();
        optRtime.addEventListener('change', function () { localStorage.setItem('opt-rtime', this.checked ? '1' : '0'); applyRtime(); });
    }

    /* ── Hide thumbnails ── */
    var optHide = document.getElementById('opt-hidethumbs');
    if (optHide) {
        optHide.checked = localStorage.getItem('opt-hidethumbs') === '1';
        function applyHide() { document.querySelectorAll('figure').forEach(function (el) { el.style.display = optHide.checked ? 'none' : ''; }); }
        applyHide();
        optHide.addEventListener('change', function () { localStorage.setItem('opt-hidethumbs', this.checked ? '1' : '0'); applyHide(); });
    }

    /* ── Hover previews toggle ── */
    var optPrevEl = document.getElementById('opt-previews');
    if (optPrevEl) {
        optPrevEl.checked = localStorage.getItem('opt-previews') !== '0';
        optPrevEl.addEventListener('change', function () { localStorage.setItem('opt-previews', this.checked ? '1' : '0'); });
    }

    /* ── Lock scroll to bottom ── */
    var optLock = document.getElementById('opt-lock'), lockInterval = null;
    if (optLock) {
        optLock.checked = localStorage.getItem('opt-lock') === '1';
        function applyLock() {
            if (optLock.checked) lockInterval = setInterval(function () { window.scrollTo(0, document.body.scrollHeight); }, 500);
            else { if (lockInterval) { clearInterval(lockInterval); lockInterval = null; } }
        }
        applyLock();
        optLock.addEventListener('change', function () { localStorage.setItem('opt-lock', this.checked ? '1' : '0'); applyLock(); });
    }

    /* ── Unread tab counter ── */
    function updateTitle() { document.title = unread > 0 ? '(' + unread + ') ' + origTitle : origTitle; }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { unread = 0; updateTitle(); } });

    /* ── FIX: Seed known post IDs from server-rendered HTML before SSE connects.
       This prevents the flash animation from firing on posts that were already
       present in the page on load / after a form submit redirect. ── */
    var knownIds = new Set();
    document.querySelectorAll('#reply-list article[id]').forEach(function (el) {
        knownIds.add(el.id.replace(/^p/, ''));
    });

    /* ── Live SSE ── */
    function connect() {
        syncEl.textContent = 'Connecting...'; syncEl.className = 'bfloat';
        var es = new EventSource(baseUrl + board + '/live.php?board=' + encodeURIComponent(board) + '&thread=' + threadId + '&since=' + lastId);
        es.addEventListener('post', function (e) {
            var d = JSON.parse(e.data);
            lastId = Math.max(lastId, d.live_event_id || 0);
            var list = document.getElementById('reply-list');
            /* FIX: use knownIds Set instead of getElementById to detect duplicates */
            if (!list || knownIds.has(String(d.post_id))) return;
            knownIds.add(String(d.post_id));
            var div = document.createElement('div'); div.innerHTML = d.post_html;
            var el = div.firstElementChild; if (!el) return;
            el.classList.add('new-post-flash');
            list.appendChild(el);
            var newBq = el.querySelector('blockquote');
            if (newBq) newBq.querySelectorAll('a.post-ref').forEach(function (a) {
                var m = a.href.match(/#p(\d+)$/); if (m) addBacklink(m[1], d.post_id);
            });
            if (document.hidden) { unread++; updateTitle(); }
            else el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        es.addEventListener('open',  function () { syncEl.textContent = 'Synched';        syncEl.className = 'bfloat connected'; });
        es.addEventListener('error', function () { syncEl.textContent = 'Reconnecting...'; syncEl.className = 'bfloat error'; es.close(); setTimeout(connect, 3000); });
    }
    connect();
})();
</script>
</body>
</html>
