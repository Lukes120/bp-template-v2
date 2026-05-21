<?php
/**
 * Bootstrap comune a tutti gli endpoint api/*.php.
 * - Carica config (env), DB, calcoli
 * - Helper CORS/JSON
 * - Auth via session token (cookie bp_session)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calcoli.php';

/**
 * Header di sicurezza + JSON content-type. Da chiamare in cima a ogni endpoint API.
 * L'app è single-origin (frontend e backend stessa origine), quindi NIENTE wildcard CORS.
 *
 * Compatibile con esposizione Internet via reverse proxy:
 *  - HSTS ha senso solo dietro HTTPS (il proxy IT termina TLS)
 *  - CSP minimale che ammette gli asset esterni usati (Google Fonts + cdnjs Font Awesome)
 *  - X-Frame-Options DENY: l'app non va embeddata
 */
function bp_cors_json(): void {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // HSTS: 6 mesi + includeSubDomains (NO preload, lasciamo la decisione all'IT)
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'");
    // Nessun CORS wildcard: l'app è single-origin
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit; }
}

function bp_json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function bp_json_out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Restituisce l'utente corrente (array) se la sessione è valida, altrimenti null.
 * La sessione viene letta dal cookie 'bp_session' o dall'header Authorization: Bearer.
 */
function bp_current_user(): ?array {
    static $cached = false;
    static $user   = null;
    if ($cached) return $user;
    $cached = true;
    $token = $_COOKIE['bp_session'] ?? null;
    if (!$token) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) $token = $m[1];
    }
    $user = bp_session_resolve($token);
    // Sliding session: ogni request autenticata estende la TTL di BP_SESSION_TTL.
    // Refresh allineato fra DB (bp_session_touch) e cookie browser (bp_session+bp_csrf).
    // headers_sent() protegge da endpoint che hanno gia' iniziato a stampare output.
    if ($user && $token && !headers_sent()) {
        bp_session_touch($token);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $exp = time() + BP_SESSION_TTL;
        setcookie('bp_session', $token, [
            'expires'  => $exp, 'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        $csrf = $_COOKIE['bp_csrf'] ?? null;
        if ($csrf !== null && $csrf !== '') {
            setcookie('bp_csrf', $csrf, [
                'expires'  => $exp, 'path' => '/', 'secure' => $secure,
                'httponly' => false, 'samesite' => 'Strict',
            ]);
        }
    }
    return $user;
}

/**
 * Blocca l'esecuzione se non c'è una sessione valida.
 * Da chiamare in cima a tutti gli endpoint protetti dopo bp_cors_json().
 */
function bp_require_auth(): array {
    $u = bp_current_user();
    if (!$u) bp_json_out(['error' => 'Non autenticato'], 401);
    return $u;
}

function bp_require_role(string ...$roles): array {
    $u = bp_require_auth();
    if (!in_array($u['ruolo'], $roles, true)) {
        bp_json_out(['error' => 'Permesso negato'], 403);
    }
    return $u;
}

/**
 * CSRF protection — double-submit cookie pattern.
 * Il client riceve un cookie 'bp_csrf' al login e DEVE rinviarlo come header X-Bp-Csrf
 * su ogni POST/DELETE. Il server verifica che cookie e header siano identici.
 *
 * Da chiamare su tutti gli endpoint mutating DOPO bp_require_auth().
 * Non applicare a /api/login.php (sessione non ancora stabilita) e /api/logout.php.
 */
function bp_require_csrf(): void {
    $cookie = $_COOKIE['bp_csrf'] ?? '';
    $header = $_SERVER['HTTP_X_BP_CSRF'] ?? '';
    if (!$cookie || !$header || !hash_equals($cookie, $header)) {
        bp_audit('csrf_fail', 'auth', null, 'token mismatch');
        bp_json_out(['error' => 'CSRF token invalido. Ricarica la pagina.'], 403);
    }
}

/**
 * Attore per l'audit log (= utente loggato, oppure null per endpoint pubblici).
 */
function bp_actor(): ?array {
    $u = bp_current_user();
    if (!$u) return null;
    return ['id' => $u['id'], 'nome' => $u['nome']];
}
