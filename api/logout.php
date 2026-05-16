<?php
require_once __DIR__ . '/../core/bootstrap.php';
bp_cors_json();

$token = $_COOKIE['bp_session'] ?? null;
if (!$token) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) $token = $m[1];
}
if ($token) {
    bp_session_destroy($token);
    setcookie('bp_session', '', ['expires' => time() - 3600, 'path' => '/']);
}
bp_audit('logout', 'auth', null, null, bp_actor());
bp_json_out(['ok' => true]);
