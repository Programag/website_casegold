<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/users.php';
require_once __DIR__ . '/inc/auth.php';

header('Content-Type: application/json');
logout_session();
echo json_encode(['ok' => true]);
