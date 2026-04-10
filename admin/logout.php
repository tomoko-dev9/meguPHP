<?php
// logout.php
require_once __DIR__ . '/../includes/core.php';
session_destroy();
redirect(BASE_URL);
