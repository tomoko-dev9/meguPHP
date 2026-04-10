<?php
// ============================================================
//  config.php  —  edit before first run
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'lol');
define('DB_PASS', 'password');
define('DB_NAME', 'imageboard');

define('SITE_TITLE',    'MeguPHP');
define('SITE_DESC',     'A PHP imageboard with live posting');
define('SITE_EMAIL',    'admin@example.com');

// BASE_URL: matches your DocumentRoot + vhost setup.
// With 000-default.conf pointing DocumentRoot at /var/www/html/imageboard,
// and the site served from the root domain:
//   http://yoursite.com/     →  'http://yoursite.com/'
//   http://yoursite.com/imageboard/ →  'http://yoursite.com/imageboard/'
define('BASE_URL',   'https://kernel.forum/');          // ← trailing slash, edit this

define('UPLOAD_DIR', __DIR__ . '/public/uploads/'); // filesystem path — do not change
define('UPLOAD_URL', BASE_URL . 'public/uploads/'); // URL to uploads folder

define('MAX_FILE_SIZE', 4 * 1024 * 1024);   // 4 MB — keep in sync with php_value in 000-default.conf
define('THUMB_W',       250);
define('THUMB_H',       250);
define('THREADS_PER_PAGE', 10);
define('REPLIES_PREVIEW',   5);             // replies shown on board index
define('MAX_REPLIES',     500);             // lock thread after this many replies

define('ALLOWED_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

define('SESSION_NAME',   'ibsession');
define('TRIPCODE_SALT',  'change-this-salt-to-something-random-and-secret');

// SSE reconnect hint (milliseconds sent to browser)
define('SSE_RETRY_MS', 2000);
