<?php
require_once __DIR__ . '/../includes/core.php';

$board_uri = isset($_GET['board'])
    ? preg_replace('/[^a-z0-9]/', '', $_GET['board'])
    : basename(dirname($_SERVER['SCRIPT_NAME']));

$board = get_board($board_uri);
if (!$board) { http_response_code(404); die('<h1>Board not found</h1>'); }

$page   = max(0, (int)($_GET['page'] ?? 0));
$offset = $page * THREADS_PER_PAGE;

$stmt = db()->prepare("
    SELECT SQL_CALC_FOUND_ROWS * FROM posts
    WHERE board_uri = ? AND thread_id IS NULL AND deleted = 0
    ORDER BY sticky DESC, last_reply DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$board_uri, THREADS_PER_PAGE, $offset]);
$threads       = $stmt->fetchAll();
$total_threads = (int)db()->query("SELECT FOUND_ROWS()")->fetchColumn();
$total_pages   = max(1, (int)ceil($total_threads / THREADS_PER_PAGE));

$thread_ids       = array_map('intval', array_column($threads, 'board_post_id'));
$thread_replies   = [];
$thread_omits     = [];
$thread_backlinks = [];
foreach ($thread_ids as $tid) {
    $thread_replies[$tid]   = [];
    $thread_omits[$tid]     = 0;
    $thread_backlinks[$tid] = [];
}

if (!empty($thread_ids)) {
    $ph = implode(',', array_fill(0, count($thread_ids), '?'));

    $rc = db()->prepare("
        SELECT thread_id, COUNT(*) AS cnt FROM posts
        WHERE board_uri = ? AND thread_id IN ($ph) AND deleted = 0
        GROUP BY thread_id
    ");
    $rc->execute(array_merge([$board_uri], $thread_ids));
    foreach ($rc->fetchAll() as $row) {
        $tid = (int)$row['thread_id'];
        $thread_omits[$tid] = max(0, (int)$row['cnt'] - REPLIES_PREVIEW);
    }

    $rp = db()->prepare("
        SELECT * FROM (
            SELECT p.*, ROW_NUMBER() OVER (PARTITION BY p.thread_id ORDER BY p.created_at DESC) AS rn
            FROM posts p
            WHERE p.board_uri = ? AND p.thread_id IN ($ph) AND p.deleted = 0
        ) ranked WHERE rn <= ?
        ORDER BY thread_id ASC, created_at ASC
    ");
    $rp->execute(array_merge([$board_uri], $thread_ids, [REPLIES_PREVIEW]));
    foreach ($rp->fetchAll() as $r) {
        $thread_replies[(int)$r['thread_id']][] = $r;
    }

    $bl = db()->prepare("
        SELECT board_post_id, thread_id, body FROM posts
        WHERE board_uri = ? AND thread_id IN ($ph) AND deleted = 0
    ");
    $bl->execute(array_merge([$board_uri], $thread_ids));
    foreach ($bl->fetchAll() as $r) {
        $tid = (int)$r['thread_id'];
        if (isset($thread_backlinks[$tid]) && preg_match('/>>'.$tid.'\b/', $r['body'] ?? '')) {
            $thread_backlinks[$tid][] = (int)$r['board_post_id'];
        }
    }
}

session_write_close();

$weekdays = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];
function idx_date(string $ts, array $wd): string {
    $dt = new DateTime($ts);
    return $dt->format('d M Y').'('.($wd[$dt->format('D')]??$dt->format('D')).')'.$dt->format('H:i');
}

// Build the image-search + size + filename caption string
function _file_caption(array $post, string $board_uri): string {
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

// OP: figcaption (caption above) + image, floats left beside op-body
function idx_file(array $post, string $board_uri): string {
    if (empty($post['file_name'])) return '';
    $full  = UPLOAD_URL . $board_uri . '/' . h($post['file_name']);
    $thumb = UPLOAD_URL . $board_uri . '/' . h($post['thumb_name'] ?? $post['file_name']);
    $tw    = min((int)($post['thumb_w'] ?? 200), 200);
    $th    = min((int)($post['thumb_h'] ?? 200), 200);
    return '<figure class="op-file">'
         . '<figcaption>' . _file_caption($post, $board_uri) . '</figcaption>'
         . '<a target="_blank" rel="nofollow" href="' . $full . '">'
         . '<img src="' . $thumb . '" width="' . $tw . '" height="' . $th . '" loading="lazy">'
         . '</a>'
         . '</figure>';
}

// Reply file info line — rendered ABOVE the article box (outside it)
function idx_reply_fileinfo(array $post, string $board_uri): string {
    if (empty($post['file_name'])) return '';
    return '<div class="reply-fileinfo">' . _file_caption($post, $board_uri) . '</div>';
}

// Reply thumbnail — floats left INSIDE the article box, no caption
function idx_reply_thumb(array $post, string $board_uri): string {
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
    <link type="image/x-icon" rel="shortcut icon" id="favicon" href="<?= BASE_URL ?>favicon.ico">
    <meta name="description" content="Real-time imageboard">
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
        margin: 0;
        padding: 0;
    }

    a { color: #b5c8ff; }
    a:hover { color: #af005f; }
    b { color: lightblue; }
    b.admin { color: #15cffa; }
    b.moderator { color: #5f5faf; }
    em { color: #12bd7c; }
    h1 {
        font-family: "MS PGothic", Mona, "Hiragino Kaku Gothic Pro", Helvetica, sans-serif;
        font-size: 30px;
        text-shadow: #074854 1px 1px 0;
        letter-spacing: 1px;
        color: #357edd;
        margin: 0;
    }
    hr { border: none; border-top: 1px solid #2e2e2e; margin: 6px 0; }
    pre { color: #8a8a8a; font-size: 10pt; }
    .spoiler { background: #000; color: #000; }
    .spoiler:hover { color: #fff; }
    .new-post-flash { animation: npflash 1.5s ease-out; }
    @keyframes npflash {
        0%   { background-color: rgba(255,255,170,0.25); }
        100% { background-color: transparent; }
    }

    /* ── Banner ── */
    #banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        font-size: 9pt;
        background: rgba(46,46,46,0.85);
        border-bottom: 1px solid #2a2a2a;
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

    /* ── Pagination ── */
    .pagination-wrap { margin: 4px 8px; font-size: 9pt; color: #389eb6; }
    nav.pagination { display: inline; }
    nav.pagination a, nav.pagination strong { margin: 0 3px; }
    nav.pagination a { color: #b5c8ff; text-decoration: none; }
    nav.pagination a:hover { color: #af005f; }
    nav.pagination strong { color: #389eb6; font-weight: normal; }

    /* ── Checkboxes ── */
    input.postCheckbox {
        display: inline !important;
        visibility: visible !important;
        opacity: 1 !important;
        position: static !important;
        width: 13px !important;
        height: 13px !important;
        -webkit-appearance: checkbox !important;
        appearance: checkbox !important;
        margin: 0 3px 0 0 !important;
        padding: 0 !important;
        vertical-align: middle;
        flex-shrink: 0;
    }

    /* ══════════════════════════════════════════════════════
       IMAGE SEARCH LINKS — matches original vichan style
       Only "G" is visible by default; others hidden unless
       the user enables them in Options (JS toggles class).
    ══════════════════════════════════════════════════════ */
    a.imageSearch        { font-size: 8pt; color: #64c0e8; text-decoration: none; margin-right: 1px; }
    a.imageSearch:hover  { color: #af005f; }
    /* Hide non-Google search links by default; JS re-shows per user pref */
    a.imageSearch.iqdb,
    a.imageSearch.saucenao,
    a.imageSearch.desustorage { display: none; }
    body.show-iqdb        a.imageSearch.iqdb        { display: inline; }
    body.show-saucenao    a.imageSearch.saucenao    { display: inline; }
    body.show-desustorage a.imageSearch.desustorage { display: inline; }

    /* ══════════════════════════════════════════════════════
       OP FILE CAPTION — sits above the OP thumbnail
    ══════════════════════════════════════════════════════ */
    figure.op-file {
        float: left;
        clear: none;
        margin: 0 14px 6px 0;
        max-width: 200px;
    }
    figure.op-file figcaption {
        font-size: 8pt;
        color: #8a8a8a;
        margin-bottom: 3px;
        line-height: 1.5;
        white-space: nowrap;
    }
    figure.op-file figcaption i { font-style: normal; color: #8a8a8a; }
    figure.op-file figcaption i a { color: #b5c8ff; text-decoration: none; }
    figure.op-file figcaption i a:hover { color: #af005f; }
    figure.op-file > a img {
        display: block;
        max-width: 200px;
        max-height: 200px;
        cursor: pointer;
    }

    /* ══════════════════════════════════════════════════════
       OP THREAD BLOCK
       Clearfix wrapper; image floats left, op-body beside it
    ══════════════════════════════════════════════════════ */
    .thread-section {
        display: block;
        margin: 8px 8px 0 8px;
        overflow: hidden; /* clearfix */
    }

    /* op-body: sits to the right of the floated figure via BFC */
    .thread-section > .op-body {
        overflow: hidden;
    }

    .op-header {
        font-size: 9pt;
        line-height: 2;
        margin: 0 0 2px 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 0 4px;
    }
    .op-header .subj  { color: #163fab; font-weight: bold; font-size: 9pt; }
    .op-header .name  { color: #add8e6; font-weight: bold; }
    .op-header .trip  { color: #64c0e8; }
    .op-header time   { color: #389eb6; font-size: 10pt; }

    .op-body > blockquote {
        margin: 2px 0 0 0;
        word-break: break-word;
        color: #389eb6;
        line-height: 1.5;
    }

    .backlinks-op {
        display: block;
        font-size: 8pt;
        color: #626262;
        margin: 2px 0 0 0;
    }
    .backlinks-op a { color: #8a8ff7; text-decoration: none; margin-right: 3px; }
    .backlinks-op a:hover { color: #af005f; }

    /* ══════════════════════════════════════════════════════
       REPLY FILE INFO — rendered OUTSIDE/ABOVE the article
       Indented to align with the reply boxes
    ══════════════════════════════════════════════════════ */
    .reply-fileinfo {
        font-size: 8pt;
        color: #8a8a8a;
        margin: 4px 0 0 34px;  /* left-indent matches article margin */
        line-height: 1.5;
        white-space: nowrap;
        clear: both;
    }
    .reply-fileinfo i { font-style: normal; color: #8a8a8a; }
    .reply-fileinfo i a { color: #b5c8ff; text-decoration: none; }
    .reply-fileinfo i a:hover { color: #af005f; }

    /* ══════════════════════════════════════════════════════
       REPLIES WRAPPER
    ══════════════════════════════════════════════════════ */
    .replies-wrap {
        clear: both;
        margin: 2px 8px 0 8px;
    }

    /* Each reply article — self-contained box */
    .replies-wrap article {
        display: block;
        overflow: hidden; /* clearfix for inner float */
        background: rgba(28,29,34,0.82);
        border: 1px solid #252525;
        margin: 2px 0 2px 18px;
        padding: 3px 6px 4px 6px;
        width: fit-content;
        max-width: calc(100% - 18px);
    }

    /* Reply header line */
    .reply-header {
        font-size: 9pt;
        line-height: 1.9;
        margin: 0 0 1px 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 0 4px;
    }
    .reply-header .name { color: #add8e6; font-weight: bold; }
    .reply-header .trip { color: #64c0e8; }
    .reply-header time  { color: #389eb6; font-size: 10pt; }

    /* Reply thumbnail — floats left INSIDE article, no caption */
    figure.reply-file {
        float: left;
        clear: none;
        margin: 2px 8px 4px 0;
        max-width: 125px;
    }
    figure.reply-file > a img {
        display: block;
        max-width: 125px;
        max-height: 125px;
        cursor: pointer;
    }

    /* Reply body text — BFC beside floated thumb */
    .replies-wrap article > blockquote {
        overflow: hidden;
        margin: 2px 0 0 0;
        word-break: break-word;
        color: #389eb6;
        line-height: 1.5;
    }

    /* Omitted posts notice */
    .omit {
        display: block;
        font-size: 9pt;
        color: #626262;
        margin: 2px 0 2px 18px;
        clear: both;
    }
    .omit a { color: #b5c8ff; }
    .omit a:hover { color: #af005f; }

    /* [Reply] link */
    aside.act.posting {
        display: block;
        clear: both;
        font-size: 9pt;
        margin: 4px 0 0 18px;
        background: transparent;
        border: none;
        padding: 0;
    }
    aside.act.posting a { color: #b5c8ff; text-decoration: none; }
    aside.act.posting a:hover { color: #af005f; }

    /* [Expand] [Last 100] links — brackets are in the HTML, suppress any from base.css */
    .expansionLinks { font-size: 9pt; }
    .expansionLinks::before, .expansionLinks::after { content: none !important; }
    .expansionLinks a { color: #b5c8ff; text-decoration: none; }
    .expansionLinks a:hover { color: #af005f; }

    /* Text colours */
    .quote-text  { color: #789922; }
    .post-ref    { color: #8a8ff7; text-decoration: none; }
    .post-ref:hover { color: #af005f; }
    blockquote a { color: #8a8ff7; }
    .act-del a   { color: #af005f; font-size: 8pt; text-decoration: none; margin-left: 4px; }
    .act-del a:hover { text-decoration: underline; }
    nav.post-nav a { color: #b5c8ff; text-decoration: none; font-size: 10pt; }
    nav.post-nav a:hover { color: #af005f; }

    /* ══════════════════════════════════════════════════════
       NEW THREAD FORM
    ══════════════════════════════════════════════════════ */
    #new-thread-link { display: inline-block; margin: 4px 8px; font-size: 9pt; }
    #new-thread-link a { color: #b5c8ff; text-decoration: none; }
    #new-thread-link a:hover { color: #af005f; }

    section.post-form-section {
        margin: 0 8px 4px 8px;
        padding: 4px 6px;
    }
    section.post-form-section blockquote { margin: 2px 0 4px 0; }
    section.post-form-section blockquote p { margin: 0; padding: 0; }

    textarea#trans {
        display: block;
        resize: vertical;
        min-height: 80px;
        width: 300px;
        border: 0;
        padding: 0;
        background: transparent;
        color: #8a8a8a;
        outline: none;
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
        font-size: 10pt;
    }

    #subject-row { margin-bottom: 4px; }
    input#subject {
        border: 0; padding: 0; background: transparent; color: #8a8a8a;
        outline: none;
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
        font-size: 10pt;
        width: 300px;
    }

    form.post-bottom { display: inline; margin: 0; }
    form.post-bottom input[type="button"],
    form.post-bottom input[type="submit"] { font-size: 9pt; cursor: pointer; margin-right: 4px; }
    input#toggle {
        background: url('<?= BASE_URL ?>public/css/ui/pane.png') transparent no-repeat center center;
        background-size: 78px 19px;
        border: 0; height: 19px; width: 78px;
        cursor: pointer; vertical-align: middle;
    }
    #form-status { font-size: 8pt; color: #af005f; }

    /* ── Hover preview ── */
    #post-preview {
        display: none;
        position: fixed;
        z-index: 12000;
        max-width: 380px;
        min-width: 160px;
        background: rgba(20,21,26,0.97);
        border: 1px solid #389eb6;
        padding: 6px 10px 8px 10px;
        pointer-events: none;
        box-shadow: 0 4px 18px rgba(0,0,0,0.75);
        font-size: 9pt;
        color: #389eb6;
    }
    #post-preview .pv-header { font-size: 8pt; margin-bottom: 4px; color: #8a8a8a; line-height: 1.6; }
    #post-preview .pv-header .name { color: #add8e6; font-weight: bold; }
    #post-preview .pv-header time  { color: #389eb6; }
    #post-preview .pv-header .post-no { color: #b5c8ff; }
    #post-preview .pv-thumb { float: left; margin: 0 8px 4px 0; }
    #post-preview .pv-thumb img { max-width: 80px; max-height: 80px; display: block; }
    #post-preview .pv-body {
        overflow: hidden;
        word-break: break-word;
        color: #389eb6;
        line-height: 1.5;
        max-height: 200px;
        overflow-y: auto;
    }
    #post-preview .pv-body p { margin: 0; }
    #post-preview .pv-body a { color: #8a8ff7; text-decoration: none; }
    #post-preview::after { content: ""; display: table; clear: both; }

    .bmodal {
        background-color: #262626;
        border-right: 1px solid #212121;
        border-bottom: 1px solid #212121;
    }
    .bmodal hr { border-top: 1px solid #99225c; }

    /* ── Responsive ── */
    @media (max-width: 600px) {
        figure.op-file { max-width: 140px; margin-right: 8px; }
        figure.op-file > a img { max-width: 140px; max-height: 140px; }
        .replies-wrap article { margin-left: 4px; }
        figure.reply-file { max-width: 100px; }
        figure.reply-file > a img { max-width: 100px; max-height: 100px; }
    }
    </style>
</head>
<body>

<fieldset id="identity" class="bmodal" style="display:none;position:fixed;top:30px;right:40px;z-index:9999;">
    <label>Name:</label> <input id="name" name="name"><br>
    <label>Email:</label> <input id="email" name="email"><br>
</fieldset>
<div id="hover_overlay"></div>
<div id="user_bg"></div>
<div id="post-preview"></div>

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

<threads>
<div id="board-header">
    <img src="<?= BASE_URL ?>banners/banner-<?= h($board_uri) ?>.png" onerror="this.style.display='none'" alt="">
    <h1>/<?= h($board_uri) ?>/<?php if (!empty($board['title'])): ?> - <?= h($board['title']) ?><?php endif; ?></h1>
</div>

<div class="pagination-wrap">
    [<nav class="pagination act">
        <strong>live</strong>
        <?php for ($p = 0; $p < $total_pages; $p++): ?>
            <?php if ($p === $page): ?>
                <strong><?= $p ?></strong>
            <?php else: ?>
                <a href="?page=<?= $p ?>" class="history"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </nav>] [<a class="history" href="<?= BASE_URL . h($board_uri) ?>/catalog">Catalog</a>]
</div>
<hr>

<!-- ── New thread form ── -->
<span id="new-thread-link"><a href="#" id="form-toggle">[New thread]</a></span>

<section class="post-form-section" id="new-thread-form-section" style="display:none;">
    <blockquote>
        <div id="subject-row">
            <input type="text" id="subject" autocomplete="off" placeholder="Subject (optional)">
        </div>
        <textarea name="body" id="trans" rows="4" autocomplete="off" placeholder="Comment…"></textarea>
    </blockquote>
    <form class="post-bottom" method="post"
          action="<?= BASE_URL . h($board_uri) ?>/post.php"
          enctype="multipart/form-data"
          id="new-thread-form">
        <input type="hidden" name="_board"  value="<?= h($board_uri) ?>">
        <input type="hidden" name="name"    id="pf-name">
        <input type="hidden" name="email"   id="pf-email">
        <input type="hidden" name="subject" id="pf-subject">
        <input type="hidden" name="body"    id="pf-body">
        <input type="button" value="Cancel" id="form-cancel">
        <input type="file"   name="image"   accept="image/jpeg,image/png,image/gif,image/webp">
        <input type="button" id="toggle"    title="Post">
        <strong id="form-status"></strong>
    </form>
</section>

<?php foreach ($threads as $t):
    $tid  = (int)$t['board_post_id'];
    $name = h($t['name'] ?: 'Anonymous');
    $trip = h($t['tripcode'] ?? '');
    $subj = h($t['subject'] ?? '');
    $iso  = (new DateTime($t['created_at']))->format('c');
    $date = idx_date($t['created_at'], $weekdays);
    $rc   = (int)($t['reply_count'] ?? 0);
    $omit = (int)($thread_omits[$tid] ?? 0);
    $replies   = $thread_replies[$tid] ?? [];
    $backlinks = $thread_backlinks[$tid] ?? [];
    $thread_url = BASE_URL . h($board_uri) . '/thread/' . $tid . '/';
?>

<!-- ═══ OP thread block ═══ -->
<div class="thread-section" id="ts-<?= $tid ?>">

    <?= idx_file($t, $board_uri) ?>

    <div class="op-body">
        <div class="op-header">
            <input type="checkbox" class="postCheckbox">
            <?php if ($subj): ?><span class="subj">「<?= $subj ?>」</span><?php endif; ?>
            <b class="name"><?= $name ?></b><?php if ($trip): ?><code class="trip"><?= $trip ?></code><?php endif; ?><?= capcode_html($t['capcode'] ?? null) ?>
            <?php if (!empty($t['sticky'])): ?>📌<?php endif; ?>
            <time datetime="<?= $iso ?>"><?= $date ?></time>
            <nav class="post-nav" style="display:inline;">
                <a href="<?= $thread_url ?>?quote=<?= $tid ?>" class="history">No.</a><a href="<?= $thread_url ?>#p<?= $tid ?>" class="history"><?= $tid ?></a>
            </nav>
            <span class="expansionLinks">[<a href="<?= $thread_url ?>" class="history">Expand</a>]<?php if ($rc >= 100): ?> [<a href="<?= $thread_url ?>?last=100" class="history">Last 100</a>]<?php endif; ?></span>
            <?php if (is_admin()): ?>
            <span class="act-del"><a href="<?= BASE_URL ?>admin/delete.php?board=<?= urlencode($board_uri) ?>&post=<?= $tid ?>" onclick="return confirm('Delete thread?')">Del</a></span>
            <?php endif; ?>
        </div>

        <blockquote
            data-name="<?= $name ?>"
            data-time="<?= $date ?>"
            data-pid="<?= $tid ?>"
            data-thumb="<?= !empty($t['thumb_name']) ? h(UPLOAD_URL . $board_uri . '/' . $t['thumb_name']) : '' ?>"
        ><?= format_post($t['body'], $board_uri) ?></blockquote>

        <?php if (!empty($backlinks)): ?>
        <small class="backlinks-op"><?php foreach ($backlinks as $blpid): ?><a href="<?= $thread_url ?>#p<?= $blpid ?>" class="post-ref">&gt;&gt;<?= $blpid ?></a> <?php endforeach; ?></small>
        <?php endif; ?>
    </div>
</div><!-- /.thread-section -->

<!-- ═══ Replies ═══ -->
<div class="replies-wrap" id="rw-<?= $tid ?>">
    <?php if ($omit > 0): ?>
    <span class="omit"><?= $omit ?> repl<?= $omit !== 1 ? 'ies' : 'y' ?> omitted. <a href="<?= $thread_url ?>" class="history">View thread</a></span>
    <?php endif; ?>

    <?php foreach ($replies as $r):
        $rid   = (int)$r['board_post_id'];
        $rname = h($r['name'] ?: 'Anonymous');
        $rtrip = h($r['tripcode'] ?? '');
        $riso  = (new DateTime($r['created_at']))->format('c');
        $rdate = idx_date($r['created_at'], $weekdays);
    ?>
    <?= idx_reply_fileinfo($r, $board_uri) ?>
    <article id="<?= $rid ?>">
        <div class="reply-header">
            <input type="checkbox" class="postCheckbox">
            <b class="name"><?= $rname ?></b><?php if ($rtrip): ?><code class="trip"><?= $rtrip ?></code><?php endif; ?><?= capcode_html($r['capcode'] ?? null) ?>
            <time datetime="<?= $riso ?>"><?= $rdate ?></time>
            <nav class="post-nav" style="display:inline;">
                <a href="<?= $thread_url ?>?quote=<?= $rid ?>" class="history">No.</a><a href="<?= $thread_url ?>#p<?= $rid ?>" class="history"><?= $rid ?></a>
            </nav>
            <?php if (is_admin()): ?>
            <span class="act-del"><a href="<?= BASE_URL ?>admin/delete.php?board=<?= urlencode($board_uri) ?>&post=<?= $rid ?>" onclick="return confirm('Delete?')">Del</a></span>
            <?php endif; ?>
        </div>
        <?= idx_reply_thumb($r, $board_uri) ?>
        <blockquote
            data-name="<?= $rname ?>"
            data-time="<?= $rdate ?>"
            data-pid="<?= $rid ?>"
            data-thumb="<?= !empty($r['thumb_name']) ? h(UPLOAD_URL . $board_uri . '/' . $r['thumb_name']) : '' ?>"
        ><?= format_post($r['body'], $board_uri) ?></blockquote>
    </article>
    <?php endforeach; ?>

    <aside class="act posting"><a href="<?= $thread_url ?>">[Reply]</a></aside>
</div><!-- /.replies-wrap -->
<hr>
<?php endforeach; ?>

<div class="pagination-wrap">
    [<nav class="pagination act">
        <strong>live</strong>
        <?php for ($p = 0; $p < $total_pages; $p++): ?>
            <?php if ($p === $page): ?>
                <strong><?= $p ?></strong>
            <?php else: ?>
                <a href="?page=<?= $p ?>" class="history"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </nav>] [<a class="history" href="<?= BASE_URL . h($board_uri) ?>/catalog">Catalog</a>]
</div>

<script id="postData" type="application/json"><?php
    $post_data = ['posts' => [], 'title' => '/' . $board_uri . '/' . (!empty($board['title']) ? ' - ' . $board['title'] : '')];
    foreach ($threads as $t) {
        $tid = (int)$t['board_post_id'];
        $entry = [
            'time'     => strtotime($t['created_at']) * 1000,
            'num'      => $tid,
            'board'    => $board_uri,
            'name'     => $t['name'] ?: 'Anonymous',
            'body'     => $t['body'] ?? '',
            'replyctr' => (int)($t['reply_count'] ?? 0),
            'omit'     => (int)($thread_omits[$tid] ?? 0),
            'replies'  => array_map('strval', array_column($thread_replies[$tid], 'board_post_id')),
        ];
        if (!empty($t['subject'])) $entry['subject'] = $t['subject'];
        if (!empty($t['file_name'])) $entry['image'] = [
            'src'   => $t['file_name'],
            'thumb' => $t['thumb_name'] ?? $t['file_name'],
            'ext'   => '.' . pathinfo($t['file_name'], PATHINFO_EXTENSION),
            'dims'  => [$t['file_w'] ?? 0, $t['file_h'] ?? 0, $t['thumb_w'] ?? 200, $t['thumb_h'] ?? 200],
            'size'  => (int)($t['file_size'] ?? 0),
            'imgnm' => $t['file_original'] ?? $t['file_name'],
        ];
        $post_data['posts'][(string)$tid] = $entry;
        foreach ($thread_replies[$tid] as $r) {
            $rid = (int)$r['board_post_id'];
            $rentry = [
                'time'  => strtotime($r['created_at']) * 1000,
                'num'   => $rid,
                'board' => $board_uri,
                'op'    => $tid,
                'name'  => $r['name'] ?: 'Anonymous',
                'body'  => $r['body'] ?? '',
            ];
            if (!empty($r['file_name'])) $rentry['image'] = [
                'src'   => $r['file_name'],
                'thumb' => $r['thumb_name'] ?? $r['file_name'],
                'ext'   => '.' . pathinfo($r['file_name'], PATHINFO_EXTENSION),
                'dims'  => [$r['file_w'] ?? 0, $r['file_h'] ?? 0, min((int)($r['thumb_w'] ?? 125), 125), min((int)($r['thumb_h'] ?? 125), 125)],
                'size'  => (int)($r['file_size'] ?? 0),
                'imgnm' => $r['file_original'] ?? $r['file_name'],
            ];
            $post_data['posts'][(string)$rid] = $rentry;
        }
    }
    echo json_encode($post_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?></script>
</threads>

<script>
(function () {
    var syncEl  = document.getElementById('sync');
    var board   = <?= json_encode($board_uri) ?>;
    var baseUrl = <?= json_encode(BASE_URL) ?>;
    var lastId  = 0;

    /* ── New thread form ── */
    var formToggle   = document.getElementById('form-toggle');
    var formSection  = document.getElementById('new-thread-form-section');
    var formCancel   = document.getElementById('form-cancel');
    var toggleBtn    = document.getElementById('toggle');
    var transTA      = document.getElementById('trans');
    var subjectInput = document.getElementById('subject');
    var pfName       = document.getElementById('pf-name');
    var pfEmail      = document.getElementById('pf-email');
    var pfSubject    = document.getElementById('pf-subject');
    var pfBody       = document.getElementById('pf-body');
    var newForm      = document.getElementById('new-thread-form');
    var identName    = document.getElementById('name');
    var identEmail   = document.getElementById('email');
    var formStatus   = document.getElementById('form-status');
    var formOpen     = false;

    function openForm()  { if (formSection) formSection.style.display = 'block'; formOpen = true; if (transTA) transTA.focus(); }
    function closeForm() { if (formSection) formSection.style.display = 'none'; if (transTA) transTA.value = ''; if (subjectInput) subjectInput.value = ''; formOpen = false; }

    if (formToggle) formToggle.addEventListener('click', function (e) { e.preventDefault(); formOpen ? closeForm() : openForm(); });
    if (formCancel) formCancel.addEventListener('click', closeForm);
    if (toggleBtn)  toggleBtn.addEventListener('click', function () {
        if (!transTA || !transTA.value.trim()) { if (formStatus) formStatus.textContent = 'Comment required.'; return; }
        pfName.value    = identName  ? identName.value  : 'Anonymous';
        pfEmail.value   = identEmail ? identEmail.value : '';
        pfSubject.value = subjectInput ? subjectInput.value : '';
        pfBody.value    = transTA.value;
        newForm.submit();
    });

    /* ── Image hover popup ── */
    var imgPopup = document.createElement('div');
    imgPopup.id = 'img-popup';
    imgPopup.style.cssText = 'display:none;position:fixed;z-index:13000;pointer-events:none;'
        + 'max-width:90vw;max-height:90vh;border:1px solid #389eb6;'
        + 'box-shadow:0 4px 24px rgba(0,0,0,0.8);background:#111;';
    var imgPopupImg = document.createElement('img');
    imgPopupImg.style.cssText = 'display:block;max-width:90vw;max-height:90vh;';
    imgPopup.appendChild(imgPopupImg);
    document.body.appendChild(imgPopup);

    function positionPopup(mx, my) {
        var pw = imgPopup.offsetWidth  || 0;
        var ph = imgPopup.offsetHeight || 0;
        var vw = window.innerWidth, vh = window.innerHeight;
        var left = mx + 20;
        var top  = my + 10;
        if (left + pw > vw - 8) left = mx - pw - 10;
        if (top  + ph > vh - 8) top  = vh - ph - 8;
        if (left < 4) left = 4;
        if (top  < 4) top  = 4;
        imgPopup.style.left = left + 'px';
        imgPopup.style.top  = top  + 'px';
    }

    function showPopup(fullSrc, mx, my) {
        imgPopupImg.src = fullSrc;
        imgPopup.style.display = 'block';
        positionPopup(mx, my);
    }

    function hidePopup() {
        imgPopup.style.display = 'none';
        imgPopupImg.src = '';
    }

    document.addEventListener('mouseover', function (e) {
        var t = e.target;
        if (t.tagName !== 'IMG') return;
        var fig = t.closest('figure');
        if (!fig) return;
        var a = t.parentElement;
        if (!a || a.tagName !== 'A' || !a.href) return;
        t.style.cursor = 'zoom-in';
        showPopup(a.href, e.clientX, e.clientY);
    });

    document.addEventListener('mousemove', function (e) {
        if (imgPopup.style.display === 'none') return;
        var t = e.target;
        if (t.tagName !== 'IMG' || !t.closest('figure')) { hidePopup(); return; }
        positionPopup(e.clientX, e.clientY);
    });

    document.addEventListener('mouseout', function (e) {
        var t = e.target;
        if (t.tagName !== 'IMG' || !t.closest('figure')) return;
        hidePopup();
    });

    /* ── Hover preview ── */
    var preview = document.getElementById('post-preview'), previewTmr = null;
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
        var m = a.href.match(/#p?(\d+)$/); if (!m) return;
        clearTimeout(previewTmr); previewTmr = setTimeout(function () { showPreview(m[1], e.clientX, e.clientY); }, 100);
    });
    document.addEventListener('mousemove', function (e) { if (preview.style.display === 'block') positionPreview(e.clientX, e.clientY); });
    document.addEventListener('mouseout',  function (e) { if (!e.target.closest('a.post-ref')) return; clearTimeout(previewTmr); hidePreview(); });

    /* ── Options / FAQ / Identity modals ── */
    var optBtn   = document.getElementById('options'),          optPanel  = document.getElementById('options-panel');
    var faqBtn   = document.getElementById('banner_FAQ'),       faqPanel  = document.getElementById('faq-panel');
    var identBtn = document.getElementById('banner_identity'),  identBox  = document.getElementById('identity');
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
    var searchLinks = ['google','iqdb','saucenao','desustorage'];
    searchLinks.forEach(function(name) {
        var el = document.getElementById('opt-' + name);
        if (!el) return;
        var stored = localStorage.getItem('opt-search-' + name);
        // google on by default, others off
        el.checked = stored !== null ? stored === '1' : (name === 'google');
        function applySearch() {
            if (el.checked) document.body.classList.add('show-' + name);
            else             document.body.classList.remove('show-' + name);
        }
        applySearch();
        el.addEventListener('change', function() { localStorage.setItem('opt-search-' + name, this.checked ? '1' : '0'); applySearch(); });
    });
    // Google is always visible via CSS (not hidden by default), only toggle others
    // Actually hide google too if unchecked
    (function() {
        var gStyle = document.createElement('style');
        document.head.appendChild(gStyle);
        var el = document.getElementById('opt-google');
        function applyGoogle() {
            gStyle.textContent = el && !el.checked ? 'a.imageSearch.google { display:none; }' : '';
        }
        if (el) { el.addEventListener('change', applyGoogle); applyGoogle(); }
    })();

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

    /* ── FIX: Seed known post IDs from server-rendered HTML before SSE connects.
       This prevents the flash animation from firing on posts that were already
       present in the page on load / after a form submit redirect. ── */
    var knownIds = new Set();
    document.querySelectorAll('.replies-wrap article[id]').forEach(function (el) {
        knownIds.add(el.id);
    });

    /* ── Live SSE ── */
    function connect() {
        syncEl.textContent = 'Connecting...'; syncEl.className = 'bfloat';
        var es = new EventSource(baseUrl + board + '/live.php?board=' + encodeURIComponent(board) + '&since=' + lastId);
        es.addEventListener('post', function (e) {
            var d = JSON.parse(e.data); lastId = Math.max(lastId, d.live_event_id || 0);
            var rw = document.getElementById('rw-' + d.thread_id); if (!rw) return;
            var aside = rw.querySelector('aside.posting');
            /* FIX: use knownIds Set instead of getElementById to detect duplicates */
            if (knownIds.has(String(d.post_id))) return;
            knownIds.add(String(d.post_id));
            var tmp = document.createElement('div'); tmp.innerHTML = d.post_html;
            var el = tmp.firstElementChild; if (!el) return;
            el.classList.add('new-post-flash');
            rw.insertBefore(el, aside);
        });
        es.addEventListener('open',  function () { syncEl.textContent = 'Synched';        syncEl.className = 'bfloat connected'; });
        es.addEventListener('error', function () { syncEl.textContent = 'Reconnecting...'; syncEl.className = 'bfloat error'; es.close(); setTimeout(connect, 3000); });
    }
    connect();
})();
</script>
</body>
</html>
