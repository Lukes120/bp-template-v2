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
$query = trim($d['query'] ?? '');
if (strlen($query) < 2) {
    bp_json_out(['ok' => true, 'risultati' => []]);
}

// Filtro per "Addetto Vendite" (sale.order.user_id) se non admin/supervisore/viewer.
// Se l'utente non ha odoo_uid (es. admin locale 'u0' senza SSO Odoo), nessun risultato.
$canSeeAll = in_array($me['ruolo'] ?? '', ['admin', 'supervisore', 'viewer'], true);
$domain = [['name', 'ilike', $query]];
if (!$canSeeAll) {
    if (empty($me['odoo_uid'])) {
        bp_json_out(['ok' => true, 'risultati' => []]);
    }
    $domain[] = ['user_id', '=', (int)$me['odoo_uid']];
}

try {
    $res = bp_odoo_call_kw_safe(
        'sale.order',
        'search_read',
        [$domain],
        ['fields' => ['name', 'partner_id', 'descrizione_progetto'], 'limit' => 10, 'order' => 'name asc']
    );

    // Sessione Odoo non recuperabile: errore distinto per UX
    if (!empty($res['error']['data']['name']) && $res['error']['data']['name'] === 'BpOdooSessionRevoked') {
        bp_json_out(['ok' => false, 'error' => 'Sessione Odoo revocata. Contatta amministratore.'], 503);
    }

    $risultati = [];
    if (isset($res['result'])) {
        foreach ($res['result'] as $o) {
            $risultati[] = [
                'codice'  => $o['name'],
                'cliente' => $o['partner_id'][1] ?? '',
                'nome'    => $o['descrizione_progetto'] ?? '',
            ];
        }
    }
    bp_json_out(['ok' => true, 'risultati' => $risultati]);
} catch (Throwable $e) {
    bp_json_out(['ok' => false, 'error' => $e->getMessage()]);
}
