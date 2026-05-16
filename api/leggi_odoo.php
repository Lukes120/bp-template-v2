<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/odoo.php';
bp_cors_json();
$me = bp_require_auth();
bp_require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

$d = bp_json_input();
$nOrdine = trim($d['nOrdine'] ?? '');
if (!$nOrdine) bp_json_out(['error' => 'Numero ordine mancante'], 400);

// Filtro per "Addetto Vendite" se non admin/supervisore.
$canSeeAll = in_array($me['ruolo'] ?? '', ['admin', 'supervisore'], true);
$domain = [['name', '=', $nOrdine]];
if (!$canSeeAll) {
    if (empty($me['odoo_uid'])) {
        bp_audit('forbidden', 'sale.order', $nOrdine, 'utente senza odoo_uid', $me);
        bp_json_out(['error' => 'Non autorizzato'], 403);
    }
    $domain[] = ['user_id', '=', (int)$me['odoo_uid']];
}

try {
    $res = bp_odoo_call_kw_safe(
        'sale.order',
        'search_read',
        [$domain],
        ['fields' => ['name', 'partner_id', 'tipo_commessa', 'descrizione_progetto'], 'limit' => 1]
    );

    // Sessione Odoo non recuperabile: errore distinto per UX
    if (!empty($res['error']['data']['name']) && $res['error']['data']['name'] === 'BpOdooSessionRevoked') {
        bp_json_out(['error' => 'Sessione Odoo revocata. Contatta amministratore.'], 503);
    }

    if (!empty($res['result'][0])) {
        $o = $res['result'][0];
        bp_json_out([
            'ok'      => true,
            'cliente' => $o['partner_id'][1] ?? '',
            'tipo'    => $o['tipo_commessa']        ?? '',
            'nome'    => $o['descrizione_progetto'] ?? '',
        ]);
    }

    // Niente match: distinguo "ordine inesistente" da "esiste ma non tuo" per dare 403 chiaro.
    if (!$canSeeAll) {
        $check = bp_odoo_call_kw_safe(
            'sale.order',
            'search_read',
            [[['name', '=', $nOrdine]]],
            ['fields' => ['id'], 'limit' => 1]
        );
        if (!empty($check['result'][0])) {
            bp_audit('forbidden', 'sale.order', $nOrdine, 'ordine di altro Addetto Vendite', $me);
            bp_json_out(['error' => 'Non autorizzato a visualizzare questo ordine'], 403);
        }
    }
    bp_json_out(['error' => 'Ordine non trovato']);
} catch (Throwable $e) {
    bp_json_out(['error' => $e->getMessage()]);
}
