<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/mailer.php';
bp_cors_json();
bp_require_role('admin');
bp_require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

$d = bp_json_input();
if (empty($d['email']) || empty($d['username'])) {
    bp_json_out(['error' => 'Dati non validi'], 400);
}

try {
    bp_mail_credenziali(
        $d['email'],
        $d['nome'] ?? $d['username'],
        $d['username'],
        $d['password'] ?? ''
    );
    bp_audit('mail_credenziali', 'utenti', null, $d['email'], bp_actor());
    bp_json_out(['ok' => true]);
} catch (Throwable $e) {
    bp_json_out(['error' => $e->getMessage()]);
}
