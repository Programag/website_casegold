<?php
/* Ręczna implementacja logowania przez Steam OpenID 2.0 - to samo, co robi
   passport-steam pod maską, tylko pisane wprost (bez Composera). */

function steam_openid_login_url(string $returnUrl, string $realm): string {
    $params = [
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'checkid_setup',
        'openid.return_to' => $returnUrl,
        'openid.realm' => $realm,
        'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ];
    return 'https://steamcommunity.com/openid/login?' . http_build_query($params);
}

/* PHP zamienia kropki w nazwach parametrów GET na podkreślenia (np.
   openid.mode -> $_GET['openid_mode']), więc do weryfikacji (gdzie trzeba
   odesłać do Steam DOKŁADNIE te same nazwy pól) parsujemy surowy query
   string sami, zamiast polegać na $_GET. */
function raw_query_params(): array {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $params = [];
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') continue;
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        $value = isset($parts[1]) ? urldecode(str_replace('+', ' ', $parts[1])) : '';
        $params[$key] = $value;
    }
    return $params;
}

/* Weryfikuje powrót ze Steam i zwraca steamid64 albo null, jeśli podpis
   jest nieprawidłowy / to nie jest w ogóle odpowiedź logowania. */
function steam_openid_verify(?array &$debug = null): ?string {
    $get = raw_query_params();
    if (($get['openid.mode'] ?? '') !== 'id_res') {
        $debug = ['stage' => 'no_id_res', 'openid_mode' => $get['openid.mode'] ?? null];
        return null;
    }

    $params = [];
    foreach ($get as $key => $value) {
        if (strpos($key, 'openid.') === 0) $params[$key] = $value;
    }
    $params['openid.mode'] = 'check_authentication';

    if (!function_exists('curl_init')) {
        $debug = ['stage' => 'no_curl_extension'];
        return null;
    }

    $ch = curl_init('https://steamcommunity.com/openid/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        error_log('Steam OpenID verify - błąd cURL: ' . $curlErr);
        $debug = ['stage' => 'curl_failed', 'curl_errno' => $curlErrno, 'curl_error' => $curlErr];
        return null;
    }
    if (strpos($response, 'is_valid:true') === false) {
        $debug = ['stage' => 'not_valid', 'response' => substr($response, 0, 300)];
        return null;
    }

    $claimedId = $params['openid.claimed_id'] ?? '';
    if (!preg_match('#/id/(\d+)$#', $claimedId, $m)) {
        $debug = ['stage' => 'no_claimed_id', 'claimed_id' => $claimedId];
        return null;
    }
    return $m[1];
}

/* Nazwa/avatar/link do profilu przez Steam Web API - odpowiednik
   profile.displayName/photos/profileurl z passport-steam. */
function steam_fetch_player_summary(string $apiKey, string $steamid): array {
    $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/'
        . '?key=' . urlencode($apiKey) . '&steamids=' . urlencode($steamid);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = $response ? json_decode($response, true) : null;
    $player = $data['response']['players'][0] ?? null;
    if (!$player) return ['displayName' => 'Gracz', 'avatar' => null, 'profileUrl' => null];
    return [
        'displayName' => $player['personaname'] ?? 'Gracz',
        'avatar' => $player['avatarfull'] ?? ($player['avatar'] ?? null),
        'profileUrl' => $player['profileurl'] ?? null,
    ];
}
