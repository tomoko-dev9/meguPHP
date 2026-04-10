# PHP Imageboard with Live Posting

A PHP + MySQL imageboard with real-time post delivery via Server-Sent Events (SSE),
styled after the doushio theme.

---

## Features

- **Live posting** — new posts appear instantly via SSE without page reload
- **Multiple boards** — create as many boards as you want from the admin panel
- **Image uploads** — JPEG, PNG, GIF, WebP with auto-thumbnailing (GD)
- **Post formatting** — greentext, spoilers (`||text||`), bold (`**text**`), italic (`*text*`), post refs (`>>123`)
- **Tripcodes** — `Name#tripkey` → `Name !B8Kx3mQd2A`
- **Thread catalog** — `/b/catalog`
- **Pagination** — board index paginates by 10 threads
- **Sage** — email `sage` to reply without bumping
- **Admin panel** — delete posts, sticky/lock threads, manage bans, reports, accounts
- **Reports** — users can report posts; mods resolve from admin panel
- **Ban system** — per-board or global bans with expiry dates

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP       | 8.0+    |
| MySQL     | 5.7+ / MariaDB 10.3+ |
| Apache    | mod_rewrite enabled |
| PHP extensions | pdo_mysql, gd, fileinfo |

---

## Installation

### 1. Deploy files

```
/var/www/html/imageboard/
├── config.php
├── install.php
├── schema.sql
├── includes/
│   └── core.php
├── admin/
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── delete.php
│   ├── manage_board.php
│   ├── create_board.php
│   ├── manage_admins.php
│   ├── change_password.php
│   ├── bans.php
│   ├── resolve_report.php
│   └── .htaccess
└── public/
    ├── index.php          ← home page
    ├── board_index.php    ← board listing (routed via .htaccess)
    ├── thread_view.php    ← thread page
    ├── post.php           ← form submission handler
    ├── live.php           ← SSE endpoint
    ├── report.php
    ├── catalog.php
    ├── .htaccess          ← URL routing
    ├── css/
    │   └── style.css
    └── uploads/           ← writable by web server
```

### 2. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE imageboard CHARACTER SET utf8mb4;"
mysql -u root -p -e "CREATE USER 'imageboard'@'localhost' IDENTIFIED BY 'yourpassword';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON imageboard.* TO 'imageboard'@'localhost';"
mysql -u root -p imageboard < schema.sql
```

### 3. Edit config.php

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'imageboard');
define('DB_PASS', 'yourpassword');
define('DB_NAME', 'imageboard');

define('SITE_TITLE', 'My Imageboard');
define('BASE_URL',   'http://yourdomain.com/imageboard/public/');
define('UPLOAD_DIR', '/var/www/html/imageboard/public/uploads/');
define('TRIPCODE_SALT', 'some-long-random-string-here');
```

### 4. Set permissions

```bash
mkdir -p public/uploads
chmod 755 public/uploads
chown www-data:www-data public/uploads
```

### 5. Enable mod_rewrite (Apache)

```bash
a2enmod rewrite
# Ensure AllowOverride All in your VirtualHost
systemctl reload apache2
```

### 6. Run the installer

Navigate to `http://yourdomain.com/imageboard/install.php` in your browser,
create your admin account, then **delete install.php**.

### 7. Log in

Go to `http://yourdomain.com/imageboard/public/admin/` and log in.
Create boards from the admin panel, or the default `/b/` and `/g/` are pre-created.

---

## URL Structure

| URL | Description |
|-----|-------------|
| `/` | Home / board list |
| `/b/` | Board index (threads) |
| `/b/thread/123/` | Thread view |
| `/b/catalog` | Catalog view |
| `/b/post.php` | Post submission (POST only) |
| `/b/live.php` | SSE stream for live posts |
| `/b/report/123` | Report a post |
| `/admin/` | Admin panel |

---

## Live Posting (SSE)

The board index and thread pages connect to `live.php` via `EventSource`.

- Board index: listens for all new posts on the board, appends replies to visible threads
- Thread view: listens only for posts in that thread, appends them live
- Reconnects automatically on disconnect
- SSE connections time out after ~55 seconds; the browser reconnects immediately

**For production with many concurrent users**, consider:
- nginx with `proxy_buffering off` for SSE
- Redis pub/sub instead of MySQL polling in `live.php`
- PHP-FPM with a generous `max_children`

---

## Nginx Config (alternative to Apache)

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/html/imageboard/public;
    index index.php;

    # SSE: disable buffering for live.php
    location ~ ^/[a-z0-9]+/live\.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/live.php;
        fastcgi_param QUERY_STRING $query_string;
        fastcgi_buffering off;
        proxy_buffering off;
    }

    # Board index
    location ~ ^/([a-z0-9]+)/?$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/board_index.php;
        fastcgi_param QUERY_STRING board=$1&$query_string;
    }

    # Thread
    location ~ ^/([a-z0-9]+)/thread/([0-9]+)/?$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/thread_view.php;
        fastcgi_param QUERY_STRING board=$1&thread=$2&$query_string;
    }

    # PHP files
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }

    location / { try_files $uri $uri/ =404; }
}
```

---

## Customisation

- **Board rules / sidebar**: edit `board_index.php` and `thread_view.php`
- **Post formatting**: edit `format_post()` in `includes/core.php`
- **Theme**: edit `public/css/style.css`
- **Max file size**: `MAX_FILE_SIZE` in `config.php`
- **Threads per page**: `THREADS_PER_PAGE` in `config.php`
- **Reply preview count**: `REPLIES_PREVIEW` in `config.php`

---

## Security Notes

- Change `TRIPCODE_SALT` to a long random string before first use
- Delete `install.php` after setup
- Upload directory should not be executable (Apache: `php_admin_flag engine off` for uploads dir)
- All user input is parameterised via PDO prepared statements
- HTML output is escaped via `h()` (htmlspecialchars)
- File uploads validated by MIME type via `mime_content_type()`
