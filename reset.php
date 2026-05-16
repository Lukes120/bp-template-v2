<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/db.php';

$expected = bp_env('RESET_TOKEN');
$token = $_GET['token'] ?? '';
if (!$expected || $token !== $expected) {
    die('<h2 style="font-family:Arial">Token non valido.</h2>');
}

// Drop e ricrea tabelle (utenti.utenti.utenti.utenti)
$pdo = bp_db();
$pdo->exec('DROP TABLE IF EXISTS utenti');
$pdo->exec('DROP TABLE IF EXISTS offerte');
// audit_log NON viene cancellato volutamente
bp_db_migrate($pdo);
bp_db_seed_admin($pdo);
bp_audit('reset', 'system', null, 'reset.php eseguito');

echo '<h2 style="color:green;font-family:Arial">Reset completato!</h2>';
echo '<p style="font-family:Arial"><b>Login:</b> admin / admin123</p>';
echo '<p style="font-family:Arial;color:red"><b>ELIMINA o disabilita questo file dal server!</b></p>';
echo '<p style="font-family:Arial"><a href="./">Vai all\'app</a></p>';
