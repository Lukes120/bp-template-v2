<?php
/**
 * Helper per chiamate JSON-RPC ad Odoo (auth + call_kw).
 * Le credenziali sono lette dalle variabili d'ambiente caricate da config.php.
 *
 * Cache sessione (v=48): bp_odoo_call_kw_safe() riusa lo stesso session_id Odoo
 * per BP_ODOO_SESSION_TTL secondi (default 5h). Se Odoo risponde "session expired"
 * la cache viene invalidata e la chiamata ripetuta una sola volta con sessione fresh.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const BP_ODOO_SESSION_TTL = 18000; // 5 ore

/* ============== LOGIN BASE (cookie file, usato dal flusso SSO) ============== */

function bp_odoo_login(string &$cookieFile): bool {
    $url = bp_env('ODOO_URL');
    $db  = bp_env('ODOO_DB');
    $user = bp_env('ODOO_USERNAME');
    $pass = bp_env('ODOO_PASSWORD');
    if (!$url || !$db || !$user || !$pass) {
        throw new RuntimeException('Credenziali Odoo mancanti in credentials.env');
    }
    $cookieFile = tempnam(sys_get_temp_dir(), 'odoo_');
    $res = bp_odoo_call($url . '/web/session/authenticate', [
        'jsonrpc' => '2.0',
        'method'  => 'call',
        'params'  => ['db' => $db, 'login' => $user, 'password' => $pass],
    ], $cookieFile);
    return !empty($res['result']['uid']);
}

function bp_odoo_call(string $url, array $payload, string $cookieFile): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res === false ? null : json_decode($res, true);
}

/**
 * Verifica se le credenziali fornite sono valide su Odoo.
 * Usato per il login SSO: tutti gli utenti che hanno già accesso a Odoo possono
 * accedere a BpTemplate con le stesse credenziali.
 *
 * Ritorna array con dati utente Odoo se ok, null se credenziali non valide.
 */
function bp_odoo_authenticate(string $login, string $password): ?array {
    $url = bp_env('ODOO_URL');
    $db  = bp_env('ODOO_DB');
    if (!$url || !$db) return null;

    $cookieFile = tempnam(sys_get_temp_dir(), 'odoo_sso_');
    $res = bp_odoo_call($url . '/web/session/authenticate', [
        'jsonrpc' => '2.0',
        'method'  => 'call',
        'params'  => ['db' => $db, 'login' => $login, 'password' => $password],
    ], $cookieFile);

    if (empty($res['result']['uid'])) {
        @unlink($cookieFile);
        return null;
    }

    $r = $res['result'];
    // Estraggo i dati utili: uid, login, name, partner display name
    $info = [
        'uid'      => (int)$r['uid'],
        'login'    => $r['username']    ?? $login,
        'name'     => $r['name']        ?? ($r['username'] ?? $login),
        'partner_display_name' => $r['partner_display_name'] ?? null,
        'company_id' => $r['user_companies']['current_company'] ?? null,
    ];

    // Provo a leggere l'email del partner via call_kw
    try {
        $ru = bp_odoo_call_kw($cookieFile, 'res.users', 'read', [[$r['uid']], ['email', 'name', 'login']], []);
        if (!empty($ru['result'][0])) {
            $info['email'] = $ru['result'][0]['email'] ?? $login;
            $info['name']  = $ru['result'][0]['name']  ?? $info['name'];
        }
    } catch (Throwable $e) { /* non bloccare il login se la read fallisce */ }

    @unlink($cookieFile);
    return $info;
}

function bp_odoo_call_kw(string $cookieFile, string $model, string $method, array $args = [], array $kwargs = []): ?array {
    $url = bp_env('ODOO_URL') . '/web/dataset/call_kw';
    return bp_odoo_call($url, [
        'jsonrpc' => '2.0',
        'method'  => 'call',
        'params'  => [
            'model'  => $model,
            'method' => $method,
            'args'   => $args,
            'kwargs' => $kwargs,
        ],
    ], $cookieFile);
}

/* ============== CACHE SESSIONE (v=48) ============== */

/**
 * Ritorna un session_id Odoo valido. Riusa la cache se non scaduta, altrimenti
 * fa login fresh con le credenziali statiche e popola la cache.
 */
function bp_odoo_get_session(): string {
    $row = bp_db()->query("SELECT session_id, expires_at FROM odoo_session WHERE id = 'default' LIMIT 1")->fetch();
    if ($row && strtotime($row['expires_at']) > time()) {
        return $row['session_id'];
    }
    return bp_odoo_session_refresh();
}

/**
 * Forza un nuovo login Odoo con le credenziali statiche e salva il session_id in cache.
 * Ritorna il nuovo session_id.
 */
function bp_odoo_session_refresh(): string {
    $url = bp_env('ODOO_URL');
    $db  = bp_env('ODOO_DB');
    $user = bp_env('ODOO_USERNAME');
    $pass = bp_env('ODOO_PASSWORD');
    if (!$url || !$db || !$user || !$pass) {
        throw new RuntimeException('Credenziali Odoo mancanti in credentials.env');
    }

    $ch = curl_init($url . '/web/session/authenticate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => ['db' => $db, 'login' => $user, 'password' => $pass],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Login Odoo fallito (curl error)');
    }
    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    if (empty($decoded['result']['uid'])) {
        throw new RuntimeException('Login Odoo fallito (uid mancante)');
    }

    // Estraggo session_id dagli header Set-Cookie
    $sessionId = null;
    if (preg_match_all('/^Set-Cookie:\s*session_id=([^;\s]+)/mi', $headers, $m)) {
        $sessionId = end($m[1]);
    }
    if (!$sessionId) {
        throw new RuntimeException('Login Odoo fallito (session_id non ricevuto)');
    }

    $expiresAt = date('Y-m-d H:i:s', time() + BP_ODOO_SESSION_TTL);
    $stmt = bp_db()->prepare("
        INSERT INTO odoo_session (id, session_id, expires_at, updated_at)
        VALUES ('default', :sid, :exp, datetime('now'))
        ON CONFLICT(id) DO UPDATE SET
            session_id = excluded.session_id,
            expires_at = excluded.expires_at,
            updated_at = datetime('now')
    ");
    $stmt->execute(['sid' => $sessionId, 'exp' => $expiresAt]);
    return $sessionId;
}

/**
 * Invalida la cache della sessione (forza un re-login alla prossima chiamata).
 */
function bp_odoo_invalidate_session(): void {
    bp_db()->exec("DELETE FROM odoo_session WHERE id = 'default'");
}

/**
 * Rileva se la risposta Odoo indica una sessione scaduta.
 */
function bp_odoo_is_session_expired(?array $res): bool {
    if (!$res || empty($res['error'])) return false;
    $err = $res['error'];
    if (($err['code'] ?? 0) === 100) return true;
    $name = $err['data']['name'] ?? '';
    if (is_string($name) && stripos($name, 'SessionExpired') !== false) return true;
    $msg = $err['message'] ?? '';
    if (is_string($msg) && stripos($msg, 'Session Expired') !== false) return true;
    return false;
}

/**
 * Esegue una chiamata Odoo JSON-RPC call_kw usando session_id cached.
 * In caso di session expired, invalida la cache e ripete la chiamata una sola volta.
 */
function bp_odoo_call_kw_safe(string $model, string $method, array $args = [], array $kwargs = []): ?array {
    $sid = bp_odoo_get_session();
    $res = bp_odoo_call_kw_with_session($sid, $model, $method, $args, $kwargs);
    if (bp_odoo_is_session_expired($res)) {
        bp_odoo_invalidate_session();
        $sid = bp_odoo_session_refresh();
        $res = bp_odoo_call_kw_with_session($sid, $model, $method, $args, $kwargs);
        // Se anche dopo refresh la sessione è scaduta, le credenziali statiche
        // probabilmente non sono più valide (es. password Odoo cambiata, utente disabilitato).
        // Errore distinto: l'utente lo capisce e contatta IT, invece di vedere "Ordine non trovato".
        if (bp_odoo_is_session_expired($res)) {
            return ['error' => ['data' => ['name' => 'BpOdooSessionRevoked'], 'message' => 'Sessione Odoo revocata. Contatta amministratore.']];
        }
    }
    return $res;
}

/**
 * Chiamata JSON-RPC call_kw con session_id passato via header Cookie (no file su disk).
 */
function bp_odoo_call_kw_with_session(string $sessionId, string $model, string $method, array $args = [], array $kwargs = []): ?array {
    $url = bp_env('ODOO_URL') . '/web/dataset/call_kw';
    $payload = [
        'jsonrpc' => '2.0',
        'method'  => 'call',
        'params'  => [
            'model'  => $model,
            'method' => $method,
            'args'   => $args,
            'kwargs' => $kwargs,
        ],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIE         => 'session_id=' . $sessionId,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res === false ? null : json_decode($res, true);
}
