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
        error_log("[allega_odoo] ordine_non_trovato nOrdine=$nOrdine form_id=" . ($form['id'] ?? '-') . " actor=" . ($me['email'] ?? '-'));
        bp_audit('attach_odoo_error', 'offerte', $form['id'] ?? null, "ordine_non_trovato: $nOrdine", $me);
        bp_json_out(['error' => "Ordine '$nOrdine' non trovato in Odoo"]);
    }
    $ordineId   = (int)$cerca['result'][0]['id'];
    $ordineNome = $cerca['result'][0]['name'];
    $nomeFile   = 'BP';

    // Cache PDF/XLSX su hash del payload offerta (vedi audit P1.1 2026-05-19).
    // Se l'utente riallega la stessa offerta NON modificata, riusa file da disk:
    // risparmia ~3-5s di bp_pdf_offerta (dompdf) + bp_xlsx_offerta (phpspreadsheet).
    // Hash include form completo, quindi qualunque modifica invalida la cache automaticamente.
    $cacheDir = __DIR__ . '/../data/cache_allegati';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheKey  = ($form['id'] ?? 'noid') . '_' . md5(json_encode($form));
    $pdfCache  = $cacheDir . '/' . $cacheKey . '.pdf';
    $xlsxCache = $cacheDir . '/' . $cacheKey . '.xlsx';

    if (is_file($pdfCache)) {
        $pdf = file_get_contents($pdfCache);
    } else {
        $pdf = bp_pdf_offerta($form, $utente);
        @file_put_contents($pdfCache, $pdf);
    }
    if (is_file($xlsxCache)) {
        $xlsx = file_get_contents($xlsxCache);
    } else {
        $xlsx = bp_xlsx_offerta($form, $utente);
        @file_put_contents($xlsxCache, $xlsx);
    }

    // Pulizia opportunistica: 1/100 alleghi -> rimuove file cache piu' vecchi di 30 giorni
    if (random_int(1, 100) === 1) {
        $cutoff = time() - (30 * 86400);
        foreach (glob($cacheDir . '/*.{pdf,xlsx}', GLOB_BRACE) ?: [] as $f) {
            if (@filemtime($f) < $cutoff) @unlink($f);
        }
    }

    $batch = bp_odoo_call_kw_safe('ir.attachment', 'create', [[
        [
            'name'      => $nomeFile . '.pdf',
            'type'      => 'binary',
            'datas'     => base64_encode($pdf),
            'res_model' => 'sale.order',
            'res_id'    => $ordineId,
            'mimetype'  => 'application/pdf',
        ],
        [
            'name'      => $nomeFile . '.xlsx',
            'type'      => 'binary',
            'datas'     => base64_encode($xlsx),
            'res_model' => 'sale.order',
            'res_id'    => $ordineId,
            'mimetype'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ]], ['context' => []]);
    if (isset($batch['error'])) {
        $errMsg = $batch['error']['message'] ?? 'errore sconosciuto';
        $errData = isset($batch['error']['data']) ? json_encode($batch['error']['data']) : '';
        error_log("[allega_odoo] ir.attachment.create FAIL ordineId=$ordineId ordineNome=$ordineNome form_id=" . ($form['id'] ?? '-') . " actor=" . ($me['email'] ?? '-') . " msg=$errMsg data=$errData");
        bp_audit('attach_odoo_error', 'offerte', $form['id'] ?? null, "ir_attachment_create_failed: $errMsg | ordine=$ordineNome", $me);
        bp_json_out(['error' => 'Allega Odoo fallito: ' . $errMsg]);
    }

    bp_audit('attach_odoo', 'offerte', $form['id'] ?? null, $ordineNome, bp_actor());
    if (!empty($form['id'])) {
        bp_offerta_set_allegata($form['id'], bp_actor());
    }
    bp_json_out(['ok' => true, 'ordineNome' => $ordineNome, 'allegataAt' => date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
    error_log("[allega_odoo] EXCEPTION " . get_class($e) . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . " nOrdine=" . ($nOrdine ?? '-') . " form_id=" . ($form['id'] ?? '-') . " actor=" . ($me['email'] ?? '-') . "\nTrace: " . $e->getTraceAsString());
    bp_audit('attach_odoo_error', 'offerte', $form['id'] ?? null, 'exception: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), $me);
    bp_json_out(['error' => $e->getMessage()]);
}
