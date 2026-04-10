-- Imageboard Database Schema
-- Run this to set up the database

CREATE DATABASE IF NOT EXISTS imageboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE imageboard;

-- Boards table
CREATE TABLE IF NOT EXISTS boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uri VARCHAR(10) NOT NULL UNIQUE,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    post_count INT DEFAULT 0,
    nsfw TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Posts table (both OPs and replies)
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_uri VARCHAR(10) NOT NULL,
    board_post_id INT NOT NULL,          -- per-board incrementing post number
    thread_id INT DEFAULT NULL,          -- NULL = this IS the thread OP
    subject VARCHAR(200) DEFAULT '',
    name VARCHAR(100) DEFAULT 'Anonymous',
    email VARCHAR(200) DEFAULT '',
    body TEXT NOT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    file_original VARCHAR(255) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    file_w INT DEFAULT NULL,
    file_h INT DEFAULT NULL,
    thumb_name VARCHAR(255) DEFAULT NULL,
    ip VARCHAR(45) NOT NULL,
    sticky TINYINT(1) DEFAULT 0,
    locked TINYINT(1) DEFAULT 0,
    reply_count INT DEFAULT 0,
    image_count INT DEFAULT 0,
    last_reply DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted TINYINT(1) DEFAULT 0,
    UNIQUE KEY uniq_board_post (board_uri, board_post_id),
    INDEX idx_board_thread (board_uri, thread_id),
    INDEX idx_thread_id (thread_id),
    INDEX idx_last_reply (board_uri, thread_id, last_reply),
    FOREIGN KEY (board_uri) REFERENCES boards(uri) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Board-level post ID counter
CREATE TABLE IF NOT EXISTS board_post_counter (
    board_uri VARCHAR(10) NOT NULL PRIMARY KEY,
    last_id INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SSE / live posting tracking
CREATE TABLE IF NOT EXISTS live_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_uri VARCHAR(10) NOT NULL,
    thread_id INT NOT NULL,
    post_id INT NOT NULL,
    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_live (board_uri, thread_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin accounts
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','moderator') DEFAULT 'moderator',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bans
CREATE TABLE IF NOT EXISTS bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    board_uri VARCHAR(10) DEFAULT NULL,   -- NULL = global ban
    reason TEXT,
    expires_at DATETIME DEFAULT NULL,     -- NULL = permanent
    banned_by VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reports
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    board_uri VARCHAR(10) NOT NULL,
    reason TEXT,
    reporter_ip VARCHAR(45),
    resolved TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default board
INSERT IGNORE INTO boards (uri, title, description) VALUES
    ('b', 'Random', 'The random board'),
    ('g', 'Technology', 'Technology discussion');

INSERT IGNORE INTO board_post_counter (board_uri, last_id) VALUES ('b', 0), ('g', 0);

-- Default admin (password: admin123 — CHANGE THIS)
INSERT IGNORE INTO admins (username, password_hash, role)
VALUES ('admin', '$2y$12$YourHashHere', 'admin');
