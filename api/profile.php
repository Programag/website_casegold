<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';

header('Content-Type: application/json');

$slug = $_GET['slug'] ?? '';
$u = $slug !== '' ? find_user_by_slug($slug) : null;
if (!$u) {
    echo json_encode(['found' => false]);
    exit;
}

$st = $u['state'] ?? [];

// Najwyższa ze znanych wartości (surowe level, levelWatermark, świeże
// wyliczenie z xp) - publiczny profil nie powinien pokazywać niższego
// poziomu niż ten, który gracz faktycznie już wywalczył.
$level = max(
    level_for_xp(is_numeric($st['xp'] ?? null) ? $st['xp'] : 0),
    is_numeric($st['levelWatermark'] ?? null) ? $st['levelWatermark'] : 0,
    is_numeric($st['level'] ?? null) ? $st['level'] : 0
);

echo json_encode([
    'found' => true,
    'profile' => [
        'slug' => $u['slug'],
        'displayName' => $u['display_name'],
        'avatar' => $u['avatar'],
        'profileUrl' => $u['profile_url'],
        'createdAt' => $u['created_at'] ?? null,
        'level' => $level,
        'bestPull' => $st['bestPull'] ?? null,
        'updatedAt' => is_numeric($st['updatedAt'] ?? null) ? $st['updatedAt'] : null,
    ],
]);
