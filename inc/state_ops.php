<?php
/* Wspólna infrastruktura dla NOWYCH, atomowych endpointów (api/claim-*.php,
   api/apply-*.php) — w odróżnieniu od api/state.php (które nadpisuje CAŁY
   stan payloadem od klienta, chronione mechanizmem "świeższy wygrywa"
   baseUpdatedAt/409), te endpointy liczą DELTĘ na podstawie świeżo
   zablokowanego wiersza (SELECT...FOR UPDATE) i od razu ją zapisują — sama
   blokada wiersza jest tu kontrolą współbieżności, więc nie ma potrzeby
   powtarzać mechanizmu baseUpdatedAt/409 ze state.php. Patrz plan migracji
   w podsumowaniu sesji: cel to zastąpienie wzorca "klient liczy, zapisuje
   do localStorage, najlepszym wysiłkiem wysyła cały blob" wzorcem "klient
   liczy część zabawową (RNG/animacje), serwer atomowo i natychmiast
   zapisuje wynik". */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/users.php';

function state_default_shape(): array {
    return [
        'balance' => 0,
        'inventory' => [],
        'invCounter' => 0,
        'level' => 0,
        'xp' => 0,
        'levelWatermark' => 0,
        'dailyBonusAt' => null,
        'dailyStreak' => 0,
        'freeCaseAt' => null,
        'bestPull' => null,
        'upgradeClicks' => 0,
        'casesOpened' => 0,
        'spentCases' => 0,
        'spentUpgrader' => 0,
        'battlesPlayed' => 0,
        'battlesWon' => 0,
        'questClaims' => [],
        'claimedLevelRewards' => [],
        'redeemedGiftCodes' => [],
        'freeCaseOpens' => [],
        'battleHistory' => [],
        'freeCaseCooldowns' => [],
        'xpScaleMigratedV2' => true,
        'adminOverrideAt' => null,
    ];
}

/* Blokuje wiersz gracza (FOR UPDATE) i zwraca jego zdekodowany, uzupełniony
   stan - wołać WEWNĄTRZ transakcji, tuż przed odczytem/mutacją. Zwraca
   null, gdy użytkownik nie istnieje (nie powinno się zdarzyć dla zalogowanej
   sesji, ale wołający musi to jawnie obsłużyć - patrz current_user()). */
function state_lock_user_for_update(PDO $pdo, string $steamid): ?array {
    $stmt = $pdo->prepare('SELECT state FROM users WHERE steamid = ? FOR UPDATE');
    $stmt->execute([$steamid]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $state = $row['state'] !== null ? json_decode($row['state'], true) : null;
    $state = ensure_level_watermark($state);
    return array_merge(state_default_shape(), $state ?? []);
}

/* Zapisuje stan (znaczy własny updatedAt = now_ms()) i zwraca zapisaną
   tablicę - wołać wewnątrz TEJ SAMEJ transakcji co
   state_lock_user_for_update(). */
function state_save(PDO $pdo, string $steamid, array $state): array {
    $state['updatedAt'] = now_ms();
    $stmt = $pdo->prepare('UPDATE users SET state = ? WHERE steamid = ?');
    $stmt->execute([json_encode($state), $steamid]);
    return $state;
}

/* JEDYNE miejsce nadające ID nowo dodawanym przedmiotom od tej migracji.
   Dopóki ID był czysto klienckim licznikiem, kolizja była nieszkodliwa
   (wszystko i tak lądowało w jednym nadpisywanym blobie w api/state.php);
   odkąd endpointy zaczynają operować PO ID (sprzedaż, blokada w kolejnych
   fazach), musi być jednoznaczny i pochodzić z jednego, zablokowanego pod
   FOR UPDATE źródła. */
function state_next_item_id(array &$state): int {
    $state['invCounter'] = (int) ($state['invCounter'] ?? 0) + 1;
    return $state['invCounter'];
}

/* effectiveLevel() z steam-auth.js po stronie serwera: levelWatermark nigdy
   nie spada, więc to on (nie surowe level_for_xp(xp)) jest źródłem prawdy o
   poziomie używanym do bramek (nagrody za poziom, darmowe skrzynki
   level-gated). */
function state_effective_level(array $state): int {
    $xp = is_numeric($state['xp'] ?? null) ? (float) $state['xp'] : 0;
    $watermark = is_numeric($state['levelWatermark'] ?? null) ? (int) $state['levelWatermark'] : 0;
    return max($watermark, level_for_xp($xp));
}

/* Wołać po KAŻDEJ zmianie $state['xp'] - odpowiednik efektu ubocznego
   effectiveLevel() w steam-auth.js (podbija i PERSYSTUJE levelWatermark,
   jeśli świeże xp na to pozwala). Bez tego wyświetlany poziom zostałby
   nieaktualny aż do następnego załadowania jakiejś strony (co i tak by go
   naprawiło przez identyczną logikę po stronie klienta, ale po co czekać). */
function state_bump_level_watermark(array &$state): void {
    $state['levelWatermark'] = state_effective_level($state);
}
