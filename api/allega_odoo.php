<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/odoo.php';
require_once __DIR__ . '/../core/pdf.php';
require_once __DIR__ . '/../core/excel.php';
bp_cors_json();
$me = bp_require_auth();
bp_require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

$d = bp_json_input();
if (!$d || empty($d['form']) || empty($d['nOrdine'])) {
    bp_json_out(['error' => 'Dati non validi'], 400);
}
$form    = $d['form'];
$nOrdine = $d['nOrdine'];
$utente  = $d['utente'] ?? '';

// Difesa per ruolo user: niente allega Odoo se PI/sconto non approvati.
// Stato letto dal DB (anti-bypass via DevTools sul payload form).
if (($me['ruolo'] ?? '') === 'user' && !empty($form['id'])) {
    $stmt = bp_db()->prepare("SELECT sconto_stato, prezzo_imposto_attivo FROM offerte WHERE id = :id");
    $stmt->execute(['id' => $form['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $stato     = $row['sconto_stato'] ?? '';
        $piAttivo  = !empty($row['prezzo_imposto_attivo']);
        $allegaOk  = ($stato === 'approvato') || ($stato === '' && !$piAttivo);
        if (!$allegaOk) {
            bp_audit('forbidden', 'offerte', $form['id'], 'attach_odoo_non_approvato', $me);
            bp_json_out(['error' => 'Approvazione richiesta prima di allegare a Odoo.'], 403);
        }
    }
}

try {
    $cerca = bp_odoo_call_kw_safe(
        'sale.order',
        'search_read',
        [[['name', '=', $nOrdine]]],
        ['fields' => ['id', 'name'], 'limit' => 1, 'context' => []]
    );
    if (empty($cerca['result'])) {
        bp_json_out(['error' => "Ordine '$nOrdine' non trovato in Odoo"]);
    }
    $ordineId   = (int)$cerca['result'][0]['id'];
    $ordineNome = $cerca['result'][0]['name'];
    $nomeFile   = 'BP';

    $pdf = bp_pdf_offerta($form, $utente);
    bp_odoo_call_kw_safe('ir.attachment', 'create', [[[
        'name'      => $nomeFile . '.pdf',
        'type'      => 'binary',
        'datas'     => base64_encode($pdf),
        'res_model' => 'sale.order',
        'res_id'    => $ordineId,
        'mimetype'  => 'application/pdf',
    ]]], ['context' => []]);

    $xlsx = bp_xlsx_offerta($form, $utente);
    bp_odoo_call_kw_safe('ir.attachment', 'create', [[[
        'name'      => $nomeFile . '.xlsx',
        'type'      => 'binary',
        'datas'     => base64_encode($xlsx),
        'res_model' => 'sale.order',
        'res_id'    => $ordineId,
        'mimetype'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]]], ['context' => []]);

    bp_audit('attach_odoo', 'offerte', $form['id'] ?? null, $ordineNome, bp_actor());
    if (!empty($form['id'])) {
        bp_offerta_set_allegata($form['id'], bp_actor());
    }
    bp_json_out(['ok' => true, 'ordineNome' => $ordineNome, 'allegataAt' => date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
    bp_json_out(['error' => $e->getMessage()]);
}
