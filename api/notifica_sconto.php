<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/mailer.php';
bp_cors_json();
bp_require_auth();
bp_require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

$d = bp_json_input();
$offerta    = $d['offerta'] ?? '';
$cliente    = $d['cliente'] ?? '';
$utente     = $d['utente']  ?? '';
$tipo       = ($d['tipo'] ?? '') === 'prezzo_imposto' ? 'prezzo_imposto' : 'sconto';
$totale     = number_format((float)($d['totale'] ?? 0), 2, ',', '.');
$mP         = (float)($d['margine'] ?? 0);
$nOrdine    = trim((string)($d['nOrdine'] ?? ''));
$offertaId  = trim((string)($d['offerta_id'] ?? '')) ?: null;
$linkUrl    = bp_env('APP_URL', '');
if ($nOrdine !== '' && $linkUrl !== '') {
    $sep = strpos($linkUrl, '?') === false ? '?' : '&';
    $linkUrl .= $sep . 'nOrdine=' . urlencode($nOrdine);
}

$utenti = bp_utenti_all();
$destinatari = array_values(array_filter($utenti, function($u) {
    return in_array($u['ruolo'] ?? '', ['supervisore', 'admin'], true) && !empty($u['email']);
}));

if (empty($destinatari)) {
    bp_json_out(['ok' => true, 'msg' => 'Nessun supervisore con email']);
}

try {
    bp_mail_richiesta_approvazione($destinatari, $tipo, $offerta, $cliente, $nOrdine, $utente, $totale, $mP, $linkUrl);
    bp_audit($tipo === 'prezzo_imposto' ? 'mail_richiesta_pi' : 'mail_richiesta_sconto', 'offerte', $offertaId, $offerta, bp_actor());
    bp_json_out(['ok' => true]);
} catch (Throwable $e) {
    bp_json_out(['ok' => false, 'error' => $e->getMessage()]);
}
