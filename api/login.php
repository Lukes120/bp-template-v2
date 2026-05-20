<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/odoo.php';
bp_cors_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

$d = bp_json_input();
$username = trim($d['username'] ?? '');
$password = $d['password'] ?? '';
$totp     = trim($d['totp'] ?? '');           // codice 2FA al secondo submit
$pendingT = trim($d['pending_token'] ?? ''); // ticket pending al 2° submit per saltare GET/POST /web/login
if (!$username || !$password) {
    bp_json_out(['error' => 'Username e password obbligatori'], 400);
}

// Rate limit: 5 tentativi falliti in 15min (per IP o username) → 429 + Retry-After 1800s.
// Pulizia opportunistica delle vecchie entry 1/100 richieste.
if (random_int(1, 100) === 1) bp_login_purge_old();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$throttle = bp_login_throttle_check($clientIp, $username);
if (!$throttle['ok']) {
    header('Retry-After: ' . $throttle['retryAfter']);
    bp_audit('login_blocked', 'auth', null, $username . ' (rate limit)');
    bp_json_out(['error' => 'Troppi tentativi falliti. Riprova fra ' . round($throttle['retryAfter'] / 60) . ' minuti.'], 429);
}

/**
 * Strategia di login:
 *  1) Prova locale (utenti seed come 'admin')
 *  2) Se locale fallisce, prova Odoo SSO con stesso username+password
 *  3) Se Odoo ok e l'utente non esiste localmente, lo crea con ruolo 'user'
 *  4) Se entrambi falliscono → 401
 */

$u = bp_utente_by_username($username);
$loggedVia = null;

if ($u && password_verify($password, $u['password_hash'])) {
    $loggedVia = 'local';
} else {
    // Prova Odoo SSO via web flow (supporta 2FA + pending ticket per fast 2nd submit)
    try {
        $odooUser = bp_odoo_authenticate($username, $password, $totp ?: null, $pendingT ?: null);
    } catch (Throwable $e) {
        $odooUser = null;
        error_log('Odoo SSO error: ' . $e->getMessage());
    }

    // 2FA richiesto: rispondi col token pending, frontend lo includera' al 2° submit per skip GET/POST /web/login
    if (is_array($odooUser) && !empty($odooUser['totp_required'])) {
        bp_json_out(['ok' => false, 'totp_required' => true, 'pending_token' => $odooUser['pending_token'] ?? '']);
    }

    if ($odooUser) {
        $loggedVia = 'odoo';
        if ($u) {
            // utente già esistente: aggiorno hash con la nuova password Odoo
            // (così il login locale futuro funziona anche senza Odoo raggiungibile)
            bp_utente_upsert([
                'id'         => $u['id'],
                'nome'       => $odooUser['name'] ?: $u['nome'],
                'username'   => $u['username'],
                'ruolo'      => $u['ruolo'],
                'email'      => $odooUser['email'] ?? $u['email'],
                'odoo_uid'   => $odooUser['uid'] ?? null,
                'firstLogin' => 0,
            ], $password);
            $u = bp_utente_by_username($username);
        } else {
            // primo accesso via Odoo: creo utente locale con ruolo 'user'
            $newId = 'odoo_' . preg_replace('/[^a-z0-9]/i', '', strtolower($username)) . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
            bp_utente_upsert([
                'id'         => $newId,
                'nome'       => $odooUser['name'] ?: $username,
                'username'   => $username,
                'ruolo'      => 'user',
                'email'      => $odooUser['email'] ?? $username,
                'odoo_uid'   => $odooUser['uid'] ?? null,
                'firstLogin' => 0,
            ], $password);
            $u = bp_utente_by_username($username);
            bp_audit('user_provisioned', 'utenti', $u['id'], 'auto-create da Odoo SSO');
        }
    }
}

if (!$loggedVia || !$u) {
    bp_login_record_failure($clientIp, $username);
    bp_audit('login_fail', 'auth', null, $username);
    bp_json_out(['error' => 'Username o password errati'], 401);
}

// Login OK: pulisco i tentativi falliti per questo username (no più lockout)
bp_login_clear_failures($username);

// Anti session fixation: invalido le sessioni esistenti dell'utente prima di crearne una nuova
bp_db()->prepare("DELETE FROM sessions WHERE user_id = :uid")->execute(['uid' => $u['id']]);

$token = bp_session_create($u['id']);
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('bp_session', $token, [
    'expires'  => time() + BP_SESSION_TTL,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// CSRF double-submit cookie: 'bp_csrf' è leggibile da JS, viene rinviato in header X-Bp-Csrf.
// SameSite=Strict rinforza: il browser non lo manda su richieste cross-site.
$csrf = bin2hex(random_bytes(32));
setcookie('bp_csrf', $csrf, [
    'expires'  => time() + BP_SESSION_TTL,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => false,
    'samesite' => 'Strict',
]);

bp_audit('login', 'auth', $u['id'], $u['username'] . ' (' . $loggedVia . ')', ['id' => $u['id'], 'nome' => $u['nome']]);

bp_json_out([
    'ok'    => true,
    'token' => $token,
    'via'   => $loggedVia,
    'user'  => [
        'id'         => $u['id'],
        'nome'       => $u['nome'],
        'username'   => $u['username'],
        'ruolo'      => $u['ruolo'],
        'email'      => $u['email'],
        'firstLogin' => (bool)$u['first_login'],
    ],
]);
