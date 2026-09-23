<?php
/* Nalicza właścicielowi kodu partnerskiego prowizję za PRAWDZIWĄ (od
   Przelewy24, potwierdzoną przez p24_verify_transaction()) wpłatę -
   jedyne miejsce wołające tę logikę to api/topup-webhook.php. Wcześniej
   (zanim doszły prawdziwe płatności) ta sama nagroda była naliczana od
   razu po kliknięciu "Kup" w api/record-affiliate-deposit.php - ten
   endpoint został usunięty razem z całą "symulowaną wpłatą", bo teraz
   zdarzenie wpłaty jest prawdziwe i pochodzi z webhooka, nie z kliknięcia. */
require_once __DIR__ . '/state_ops.php';

// Ile wirtualnych złotych dostaje twórca za każdą prawdziwą wpłaconą złotówkę.
const AFFILIATE_VIRTUAL_PER_PLN = 1000;

/* $code - znormalizowany (trim+uppercase) kod partnerski zastosowany PRZY
   zakupie (zapisany na zamówieniu w topup_orders w momencie
   api/topup-create.php, zanim jeszcze wiadomo było, czy klient w ogóle
   zapłaci). $buyerSteamid - kupujący, żeby zablokować self-referral nawet
   gdyby ktoś zdążył zmienić własny kod między zakupem a płatnością.
   Cicho nic nie robi (brak wyjątku), jeśli kod jest nieprawidłowy/już
   nieaktualny/należy do kupującego - błąd w promocji nie może zablokować
   zaksięgowania PRAWDZIWEJ wpłaty kupującego. */
function credit_affiliate_for_deposit(PDO $pdo, ?string $code, string $buyerSteamid, float $pricePln): void {
    if (!$code) return;

    $find = $pdo->prepare("SELECT steamid FROM users WHERE JSON_UNQUOTE(JSON_EXTRACT(state, '$.affiliateCode')) = ? LIMIT 1");
    $find->execute([$code]);
    $owner = $find->fetch();
    if (!$owner || $owner['steamid'] === $buyerSteamid) return;

    $pdo->beginTransaction();
    try {
        $state = state_lock_user_for_update($pdo, $owner['steamid']);
        if ($state === null || ($state['affiliateCode'] ?? null) !== $code) {
            $pdo->rollBack();
            return;
        }
        $stats = is_array($state['affiliateStats'] ?? null) ? $state['affiliateStats'] : ['timesUsed' => 0, 'totalDepositedPln' => 0, 'totalEarnedVirtual' => 0, 'totalWithdrawn' => 0];
        $stats['timesUsed'] = (int) ($stats['timesUsed'] ?? 0) + 1;
        $stats['totalDepositedPln'] = (float) ($stats['totalDepositedPln'] ?? 0) + $pricePln;
        $stats['totalEarnedVirtual'] = (float) ($stats['totalEarnedVirtual'] ?? 0) + $pricePln * AFFILIATE_VIRTUAL_PER_PLN;
        $state['affiliateStats'] = $stats;

        state_save($pdo, $owner['steamid'], $state);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('credit_affiliate_for_deposit błąd: ' . $e->getMessage());
    }
}
