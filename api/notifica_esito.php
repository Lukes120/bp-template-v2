<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/mailer.php';
bp_cors_json();
$me = bp_require_auth();
bp_require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

// Solo admin/supervisore possono notificare un esito (è loro l'azione di approvare/rifiutare)
if (!in_array($me['ruolo'] ?? '', ['admin', 'supervisore'], true)) {
    bp_json_out(['error' => 'Permesso negato'], 403);
}

$d         = bp_json_input();
$offertaId = trim((string)($d['offertaId'] ?? ''));
$esito     = ($d['esito'] ?? '') === 'approvato' ? 'approvato' : 'rifiutato';
$motivo    = trim((string)($d['motivo'] ?? ''));

if ($offertaId === '') bp_json_out(['error' => 'Offerta non specificata'], 400);

// Carica offerta dal DB
$stmt = bp_db()->prepare("SELECT * FROM offerte WHERE id = :id");
$stmt->execute(['id' => $offertaId]);
$row = $stmt->fetch();
if (!$row) bp_json_out(['error' => 'Offerta non trovata'], 404);

$offertaHy   = bp_offerta_hydrate($row);
$tipo        = !empty($offertaHy['prezzoImpostoAttivo']) ? 'prezzo_imposto' : 'sconto';
$nomeOfferta = $offertaHy['nome'] ?? '';
$clienteOff  = $offertaHy['cliente'] ?? '';
$userIdReq   = $offertaHy['userId'] ?? '';
$approvNome  = $me['nome'] ?? '';
$approvTs    = date('d/m/Y H:i');

// Cerca email del richiedente
$utenti = bp_utenti_all();
$richiedente = null;
foreach ($utenti as $u) {
    if (($u['id'] ?? '') === $userIdReq) { $richiedente = $u; break; }
}
if (!$richiedente || empty($richiedente['email'])) {
    // Nessuna email destinazione: rispondi ok ma nessun invio
    bp_json_out(['ok' => true, 'msg' => 'Richiedente senza email valida']);
}

// Build link
$nOrdine = trim((string)($offertaHy['nOrdineOdoo'] ?? ''));
$linkUrl = bp_env('APP_URL', '');
if ($nOrdine !== '' && $linkUrl !== '') {
    $sep = strpos($linkUrl, '?') === false ? '?' : '&';
    $linkUrl .= $sep . 'nOrdine=' . urlencode($nOrdine);
}

try {
    bp_mail_esito_richiesta(
        $richiedente['email'],
        $richiedente['nome'] ?? '',
        $tipo,
        $esito,
        $nomeOfferta,
        $clienteOff,
        $nOrdine,
        $motivo,
        $approvNome,
        $approvTs,
        $linkUrl
    );
    bp_audit($esito === 'approvato' ? 'mail_esito_approvato' : 'mail_esito_rifiutato', 'offerte', $offertaId, $nomeOfferta, bp_actor());
    bp_json_out(['ok' => true]);
} catch (Throwable $e) {
    bp_json_out(['ok' => false, 'error' => $e->getMessage()]);
}
