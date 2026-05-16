<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/mailer.php';
bp_cors_json();

$method = $_SERVER['REQUEST_METHOD'];
// GET (elenco utenti) — qualunque ruolo (serve al client per mappare userId → nome)
// POST/DELETE — solo admin. Eccezione: utente che modifica se stesso può fare POST sul proprio id.
if ($method === 'GET') {
    bp_require_auth();
    bp_json_out(bp_utenti_all());
}

$me = bp_require_auth();
bp_require_csrf();
$d  = $method === 'POST' ? bp_json_input() : [];
$isSelfUpdate = $method === 'POST' && !empty($d['id']) && $d['id'] === $me['id'];
if (!$isSelfUpdate && $me['ruolo'] !== 'admin') {
    bp_json_out(['error' => 'Permesso negato'], 403);
}
$actor = $me;

if ($method === 'POST') {
    if (empty($d['id']) || empty($d['username']) || empty($d['nome']) || empty($d['ruolo'])) {
        bp_json_out(['error' => 'Campi obbligatori mancanti'], 400);
    }
    if ($isSelfUpdate) {
        // un utente normale non può promuoversi: forzo ruolo a quello attuale
        $d['ruolo'] = $me['ruolo'];
    }

    $existing  = bp_utente_by_id($d['id']);
    $isNew     = !$existing;
    $plainPass = $d['password'] ?? null;

    try {
        bp_utente_upsert($d, $plainPass, $actor);
    } catch (Throwable $e) {
        bp_json_out(['error' => $e->getMessage()], 400);
    }

    if ($isNew && !empty($d['email']) && $plainPass) {
        try {
            bp_mail_credenziali($d['email'], $d['nome'], $d['username'], $plainPass);
        } catch (Throwable $e) {
            error_log('mail credenziali fallita: ' . $e->getMessage());
        }
    }

    bp_json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) bp_json_out(['error' => 'id mancante'], 400);
    try {
        bp_utente_delete($id, $actor);
    } catch (Throwable $e) {
        bp_json_out(['error' => $e->getMessage()], 400);
    }
    bp_json_out(['ok' => true]);
}

bp_json_out(['error' => 'Metodo non consentito'], 405);
