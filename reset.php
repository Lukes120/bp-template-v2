<?php
/**
 * Reset emergenza: ricrea le tabelle utenti e offerte e seeda l'admin.
 *
 * SICUREZZA: questa pagina puo' essere invocata SOLO da localhost (sulla macchina VM stessa)
 * o da PHP CLI. Token URL come ulteriore safety. Audit_log non viene cancellato.
 *
 * Per usarla in produzione: collegarsi alla VM (RDP), aprire un browser sulla VM stessa,
 * navigare a http://localhost:8080/reset.php?token=<RESET_TOKEN>. Oppure da CLI:
 *   php reset.php <token>
 *
 * Comportamento: cancella TUTTI gli utenti (compresi quelli SSO auto-provisionati) e TUTTE
 * le offerte. Re-seeda admin. Da usare SOLO se admin ha perso le credenziali e non c'e' altro modo.
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/db.php';

$isCli      = PHP_SAPI === 'cli';
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal    = in_array($clientIp, ['127.0.0.1', '::1'], true);
$expected   = bp_env('RESET_TOKEN');
$tokenIn    = $isCli ? ($argv[1] ?? '') : ($_GET['token'] ?? '');

if (!$isCli && !$isLocal) {
    http_response_code(403);
    die('<h2 style="font-family:Arial;color:#b91c1c">Reset accessibile solo da localhost o CLI.</h2>');
}
if (!$expected || $tokenIn !== $expected) {
    bp_audit('reset_token_fail', 'system', null, "ip=$clientIp sapi=" . PHP_SAPI);
    http_response_code(403);
    die($isCli ? "Token non valido.\n" : '<h2 style="font-family:Arial">Token non valido.</h2>');
}

$pdo = bp_db();
$pdo->exec('DROP TABLE IF EXISTS utenti');
$pdo->exec('DROP TABLE IF EXISTS offerte');
bp_db_migrate($pdo);
bp_db_seed_admin($pdo);
bp_audit('reset', 'system', null, 'reset.php eseguito da ' . ($isCli ? 'CLI' : "localhost ($clientIp)"));

if ($isCli) {
    echo "Reset completato. Admin riportato alle credenziali di default (vedi bp_db_seed_admin).\n";
} else {
    echo '<h2 style="color:green;font-family:Arial">Reset completato.</h2>';
    echo '<p style="font-family:Arial">Le credenziali admin sono state riportate al default (vedi data/INITIAL_ADMIN_PASSWORD.txt o bp_db_seed_admin in core/db.php). <b>Cambia subito la password dopo il login.</b></p>';
    echo '<p style="font-family:Arial;color:#b91c1c"><b>RIMUOVI o disabilita questo file dopo l\'uso.</b></p>';
    echo '<p style="font-family:Arial"><a href="./">Vai all\'app</a></p>';
}
