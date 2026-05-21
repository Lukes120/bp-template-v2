<?php
/**
 * Generazione PDF (singola offerta + archivio).
 * Usa Dompdf, condiviso tra api/genera_pdf.php, api/archivio_pdf.php, api/allega_odoo.php.
 */

require_once __DIR__ . '/calcoli.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function bp_logo_b64(): string {
    $p = __DIR__ . '/../logo.png';
    return is_readable($p) ? base64_encode(file_get_contents($p)) : '';
}

function bp_pdf_offerta(array $form, string $utente): string {
    $c = bp_calc_all($form);
    $logoB64 = bp_logo_b64();
    $logoTag = $logoB64 ? '<img src="data:image/png;base64,' . $logoB64 . '" style="max-width:140px;max-height:38px">' : '';

    $h  = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';
    $h .= '@page{margin:10mm}';
    $h .= 'body{font-family:Arial,sans-serif;font-size:9px;margin:0;color:#1f2937}';
    $h .= 'h3{color:#475569;font-size:9.5px;margin:5px 0 2px;border-bottom:1px solid #475569;padding-bottom:1px;text-transform:uppercase;letter-spacing:.04em}';
    $h .= 'table{border-collapse:collapse;width:100%;margin-bottom:3px}';
    $h .= 'th{background:#475569;color:white;padding:2px 5px;font-size:8.5px;text-align:left;font-weight:600}';
    $h .= 'td{border:1px solid #e2e8f0;padding:1px 4px;font-size:8.5px}';
    $h .= '.r{text-align:right}.b{font-weight:bold}.slate{color:#475569;font-weight:bold}.green{color:#15803d;font-weight:600}';
    $h .= '.tot td{background:#f1f5f9;font-weight:bold;border-top:2px solid #475569}';
    $h .= '.grand td{background:#475569;color:white;font-weight:bold;font-size:11px;padding:5px 6px}';
    $h .= '.row-pi td{background:#ede9fe;color:#5b21b6;font-weight:bold;font-size:13px;padding:8px 6px;border:2px solid #475569}';
    $h .= '.riep-block{page-break-inside:avoid}';
    $h .= '.zebra tr:nth-child(even) td{background:#fafbfc}';
    $h .= '.footer{margin-top:6px;font-size:7.5px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:3px;text-align:center}';
    $h .= '</style></head><body>';

    $h .= '<table style="border:none;margin-bottom:6px"><tr>';
    $h .= '<td style="border:none;width:150px;vertical-align:middle">' . $logoTag . '</td>';
    $h .= '<td style="border:none;vertical-align:top;padding-left:12px;border-left:3px solid #475569">';
    $h .= '<div style="font-size:12px;font-weight:bold;color:#475569;margin-bottom:2px;letter-spacing:.02em">Valutazione Commessa</div>';
    $h .= '<table style="width:auto;margin:0"><tbody>';
    $h .= '<tr><td style="border:none;color:#64748b;padding:0 6px 0 0;font-size:8.5px">Commessa:</td><td style="border:none;font-weight:bold;font-size:9px">' . htmlspecialchars($form['nome'] ?? '') . '</td>';
    $h .= '<td style="border:none;color:#64748b;padding:0 6px 0 14px;font-size:8.5px">Cliente:</td><td style="border:none;font-size:9px">' . htmlspecialchars($form['cliente'] ?? '') . '</td></tr>';
    $h .= '<tr><td style="border:none;color:#64748b;padding:0 6px 0 0;font-size:8.5px">Tipo:</td><td style="border:none;font-size:9px">' . htmlspecialchars($form['tipo'] ?? '') . '</td>';
    $h .= '<td style="border:none;color:#64748b;padding:0 6px 0 14px;font-size:8.5px">Data:</td><td style="border:none;font-size:9px">' . htmlspecialchars($form['data'] ?? '') . '</td></tr>';
    if (!empty($form['nOrdineOdoo'])) {
        $h .= '<tr><td style="border:none;color:#7c3aed;padding:0 6px 0 0;font-size:8.5px">N. Odoo:</td><td colspan="3" style="border:none;color:#7c3aed;font-weight:bold;font-size:9px">' . htmlspecialchars($form['nOrdineOdoo']) . '</td></tr>';
    }
    $h .= '</tbody></table></td></tr></table>';

    $sezioni = [
        ['titolo' => 'Manodopera',          'rows' => $c['cp'],  'tC' => $c['tCP'],  'tV' => $c['tVP'],  'cols' => ['Categoria','h/uomo','Costo/h'],          'fields' => ['categoria','oreG','costoH']],
        ['titolo' => 'Materiali',           'rows' => $c['cm'],  'tC' => $c['tCM'],  'tV' => $c['tVM'],  'cols' => ['Descrizione','Qta','Costo Unit.'],       'fields' => ['desc','qta','costoU']],
        ['titolo' => 'Servizi e Subappalti','rows' => $c['cs'],  'tC' => $c['tCS'],  'tV' => $c['tVS'],  'cols' => ['Descrizione','Qta','Costo Unit.'],       'fields' => ['desc','qta','costoU']],
        ['titolo' => 'Manutenzione',        'rows' => $c['cm2'], 'tC' => $c['tCM2'], 'tV' => $c['tVM2'], 'cols' => ['Descrizione','Qta','Costo/Un'],          'fields' => ['desc','qta','costoU']],
    ];
    foreach ($sezioni as $s) {
        // Salta sezione se vuota o con totale costo a zero (righe placeholder vuote)
        if (empty($s['rows']) || (float)$s['tC'] == 0) continue;
        $h .= '<h3>' . $s['titolo'] . '</h3><table class="zebra"><thead><tr>';
        foreach ($s['cols'] as $col) $h .= '<th>' . $col . '</th>';
        $h .= '<th class="r">Costo Tot.</th><th class="r">Markup</th><th class="r">Prezzo Vendita</th><th class="r">Margine</th></tr></thead><tbody>';
        foreach ($s['rows'] as $r) {
            // Salta riga se costo base = 0
            if ((float)($r['b'] ?? 0) == 0) continue;
            $h .= '<tr>';
            foreach ($s['fields'] as $i => $f) {
                $val = $r[$f] ?? '';
                $cls = $i === 0 ? '' : 'r';
                $h .= '<td' . ($cls ? ' class="' . $cls . '"' : '') . '>' . htmlspecialchars((string)$val) . '</td>';
            }
            $h .= '<td class="r">' . bp_fmt($r['b']) . '</td>';
            $h .= '<td class="r">' . htmlspecialchars((string)($r['markup'] ?? '0')) . '%</td>';
            $h .= '<td class="r slate">' . bp_fmt($r['pv']) . '</td>';
            $h .= '<td class="r green">' . bp_fmt($r['pv'] - $r['b']) . '</td>';
            $h .= '</tr>';
        }
        $h .= '<tr class="tot"><td colspan="3" class="b">TOTALE</td>';
        $h .= '<td class="r">' . bp_fmt($s['tC']) . '</td><td></td>';
        $h .= '<td class="r slate">' . bp_fmt($s['tV']) . '</td>';
        $h .= '<td class="r green">' . bp_fmt($s['tV'] - $s['tC']) . '</td></tr></tbody></table>';
    }

    if (!empty($c['ct']) && (float)$c['tCT'] > 0) {
        $h .= '<h3>Trasferte</h3><table class="zebra"><thead><tr><th>Descrizione</th><th class="r">Persone</th><th class="r">Giorni</th><th class="r">Costo/g</th><th class="r">Vitto</th><th class="r">Alloggio</th><th class="r">Km</th><th class="r">E/km</th><th class="r">Costo Tot.</th><th class="r">Markup</th><th class="r">Prezzo Vendita</th><th class="r">Margine</th></tr></thead><tbody>';
        foreach ($c['ct'] as $r) {
            if ((float)($r['b'] ?? 0) == 0) continue;
            $h .= '<tr>';
            foreach (['desc','persone','giorni','costoGiorno','vitto','alloggio','km','costoKm'] as $i => $f) {
                $cls = $i === 0 ? '' : 'r';
                $h .= '<td' . ($cls ? ' class="' . $cls . '"' : '') . '>' . htmlspecialchars((string)($r[$f] ?? '')) . '</td>';
            }
            $h .= '<td class="r">' . bp_fmt($r['b']) . '</td>';
            $h .= '<td class="r">' . htmlspecialchars((string)($r['markup'] ?? '0')) . '%</td>';
            $h .= '<td class="r slate">' . bp_fmt($r['pv']) . '</td>';
            $h .= '<td class="r green">' . bp_fmt($r['pv'] - $r['b']) . '</td></tr>';
        }
        $h .= '<tr class="tot"><td colspan="8" class="b">TOTALE</td>';
        $h .= '<td class="r">' . bp_fmt($c['tCT']) . '</td><td></td>';
        $h .= '<td class="r slate">' . bp_fmt($c['tVT']) . '</td>';
        $h .= '<td class="r green">' . bp_fmt($c['tVT'] - $c['tCT']) . '</td></tr></tbody></table>';
    }

    // Riepilogo dentro un blocco non spezzabile per evitare page break in mezzo
    $h .= '<div class="riep-block">';
    $h .= '<h3>Riepilogo</h3><table><thead><tr><th>Voce</th><th class="r">Costo</th><th class="r">Prezzo Vendita</th><th class="r">Margine</th><th class="r">Margine %</th></tr></thead><tbody>';
    $voci = [
        ['Manodopera',   $c['tCP'],  $c['tVP']],
        ['Materiali',    $c['tCM'],  $c['tVM']],
        ['Servizi',      $c['tCS'],  $c['tVS']],
        ['Manutenzione', $c['tCM2'], $c['tVM2']],
        ['Trasferte',    $c['tCT'],  $c['tVT']],
    ];
    foreach ($voci as [$l, $co, $v]) {
        if ((float)$co == 0 && (float)$v == 0) continue;  // salta sezione vuota anche dal riepilogo
        $h .= '<tr><td class="b">' . $l . '</td><td class="r">' . bp_fmt($co) . '</td>';
        $h .= '<td class="r slate">' . bp_fmt($v) . '</td>';
        $h .= '<td class="r green">' . bp_fmt($v - $co) . '</td>';
        $h .= '<td class="r">' . bp_fmt_pct($v > 0 ? ($v - $co) / $v * 100 : 0) . '%</td></tr>';
    }
    $h .= '<tr><td class="b">Spese Generali (' . htmlspecialchars((string)($form['speseGenerali'] ?? '5')) . '%)</td><td class="r">--</td><td class="r" style="color:#ea580c;font-weight:bold">' . bp_fmt($c['sg']) . '</td><td colspan="2"></td></tr>';
    // Riga Overmarkup esplicita (solo se >0): trasparenza sul "+X%" applicato al
    // prezzo finale, simmetrica a Spese Generali. Bp_calc_all gia' include
    // l'overmarkup in tFCalc/tFSconto, qui mostriamo solo il valore EUR aggiunto.
    if (!empty($c['overmarkup']) && (int)$c['overmarkup'] > 0) {
        $h .= '<tr><td class="b">Overmarkup (+' . (int)$c['overmarkup'] . '%)</td><td class="r">--</td><td class="r" style="color:#059669;font-weight:bold">+ ' . bp_fmt($c['overmarkupValore']) . '</td><td colspan="2"></td></tr>';
    }

    $prezzoImpostoOk = !empty($c['prezzoImpostoOk']);
    $scontoE = $c['scontoE'] ?? 0;
    if (!$prezzoImpostoOk && $scontoE > 0) {
        $scontoLabel = ($form['scontoTipo'] ?? 'pct') === 'pct'
            ? bp_fmt($form['scontoValore'] ?? 0) . '%'
            : 'EUR ' . bp_fmt($form['scontoValore'] ?? 0);
        $h .= '<tr style="background:#fef2f2"><td class="b" style="color:#dc2626">Sconto Direzione (' . $scontoLabel . ')</td><td class="r">--</td><td class="r" style="color:#dc2626;font-weight:bold">- ' . bp_fmt($scontoE) . '</td><td colspan="2"></td></tr>';
    }
    // TOTALE CALCOLATO sempre presente
    $calcStyle = $prezzoImpostoOk ? ' style="background:#f3f4f6;color:#4b5563"' : '';
    $h .= '<tr class="grand"' . $calcStyle . '><td>TOTALE CALCOLATO (IVA ESCLUSA)</td><td class="r">' . bp_fmt($c['tC']) . '</td><td class="r">' . bp_fmt($c['tFCalc']) . '</td><td class="r">' . bp_fmt($c['mECalc']) . '</td><td class="r">' . bp_fmt_pct($c['mPCalc']) . '%</td></tr>';
    if ($prezzoImpostoOk) {
        $h .= '<tr class="row-pi"><td>PREZZO IMPOSTO (IVA ESCLUSA)</td><td class="r">' . bp_fmt($c['tC']) . '</td><td class="r">' . bp_fmt($c['tFSconto']) . '</td><td class="r">' . bp_fmt($c['mE']) . '</td><td class="r">' . bp_fmt_pct($c['mP']) . '%</td></tr>';
    }
    $h .= '</tbody></table>';

    if (!empty($form['note'])) {
        $h .= '<p style="margin-top:4px;font-size:8.5px"><b style="color:#475569">Note:</b> ' . htmlspecialchars($form['note']) . '</p>';
    }
    $h .= '<div class="footer">Elaborato da <b>' . htmlspecialchars($utente) . '</b> &nbsp;·&nbsp; ' . date('d/m/Y') . ' &nbsp;·&nbsp; BP Template — Ecotel Italia</div>';
    $h .= '</div>';
    $h .= '</body></html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($h);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    return $dompdf->output();
}

function bp_pdf_archivio(array $lista): string {
    $logoB64 = bp_logo_b64();
    $logoTag = $logoB64 ? '<img src="data:image/png;base64,' . $logoB64 . '" style="max-height:45px;max-width:160px">' : '';

    $showUtente = false;
    foreach ($lista as $r) { if (!empty($r['utente'])) { $showUtente = true; break; } }

    $totRicavi = 0; $totCosti = 0; $totMargineE = 0; $rows = '';
    foreach ($lista as $idx => $o) {
        $ricavi = (float)($o['tF'] ?? 0);
        $costi  = (float)($o['tC'] ?? 0);
        $margE  = (float)($o['mE'] ?? 0);
        $margP  = round((float)($o['mP'] ?? 0), 1);
        $totRicavi += $ricavi; $totCosti += $costi; $totMargineE += $margE;
        $bg = $idx % 2 === 0 ? '#ffffff' : '#eef4fb';
        $mColor = $margP >= BP_MARGINE_GREEN ? '#15803d' : ($margP >= BP_MARGINE_YELLOW ? '#ca8a04' : '#dc2626');
        $data = $o['data'] ?? '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m)) $data = $m[3] . '/' . $m[2] . '/' . $m[1];
        $rows .= '<tr style="background:' . $bg . '">'
              . '<td class="left bold">' . htmlspecialchars($o['nome'] ?? '') . '</td>'
              . '<td class="left">' . htmlspecialchars($o['cliente'] ?? '') . '</td>'
              . '<td class="center">' . htmlspecialchars($data) . '</td>'
              . '<td class="center" style="color:#7c3aed;font-weight:bold">' . htmlspecialchars($o['nOrdineOdoo'] ?? '') . '</td>'
              . ($showUtente ? '<td class="center">' . htmlspecialchars($o['utente'] ?? '') . '</td>' : '')
              . '<td class="right slate bold">' . bp_fmt($ricavi) . '</td>'
              . '<td class="right" style="color:#b91c1c">' . bp_fmt($costi) . '</td>'
              . '<td class="right green bold">' . bp_fmt($margE) . '</td>'
              . '<td class="right bold" style="color:' . $mColor . '">' . bp_fmt_pct($margP) . '%</td>'
              . '</tr>';
    }
    $totMargineP = $totRicavi > 0 ? round(($totMargineE / $totRicavi) * 100, 1) : 0;
    $totColspan  = $showUtente ? 5 : 4;
    $totRow = '<tr class="tot-row">'
            . '<td colspan="' . $totColspan . '" class="left">TOTALI (' . count($lista) . ' offerte)</td>'
            . '<td class="right">' . bp_fmt($totRicavi) . '</td>'
            . '<td class="right">' . bp_fmt($totCosti) . '</td>'
            . '<td class="right">' . bp_fmt($totMargineE) . '</td>'
            . '<td class="right">' . bp_fmt_pct($totMargineP) . '%</td>'
            . '</tr>';

    $kpiMarginColor = $totMargineP >= BP_MARGINE_GREEN ? '#15803d' : ($totMargineP >= BP_MARGINE_YELLOW ? '#ca8a04' : '#dc2626');
    $kpiBox = '<table class="kpi-bar"><tr>'
            . '<td class="kpi"><div class="kpi-label">Offerte</div><div class="kpi-value">' . count($lista) . '</div></td>'
            . '<td class="kpi"><div class="kpi-label">Ricavi totali</div><div class="kpi-value">€ ' . bp_fmt($totRicavi) . '</div></td>'
            . '<td class="kpi"><div class="kpi-label">Costi totali</div><div class="kpi-value" style="color:#b91c1c">€ ' . bp_fmt($totCosti) . '</div></td>'
            . '<td class="kpi"><div class="kpi-label">Margine totale</div><div class="kpi-value" style="color:#15803d">€ ' . bp_fmt($totMargineE) . '</div></td>'
            . '<td class="kpi"><div class="kpi-label">Margine medio</div><div class="kpi-value" style="color:' . $kpiMarginColor . '">' . bp_fmt_pct($totMargineP) . '%</div></td>'
            . '</tr></table>';

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
@page { margin: 12mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 10px; color: #1f2937; }
.header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.header-table td { border: none; vertical-align: middle; }
.header-table .logo-cell { width: 160px; }
.header-table .title-cell { padding-left: 14px; border-left: 3px solid #475569; }
.doc-title { font-size: 15px; font-weight: bold; color: #475569; margin-bottom: 2px; letter-spacing:.02em }
.doc-sub { font-size: 9px; color: #64748b; }
.kpi-bar { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 10px; }
.kpi-bar .kpi { background: #f8fafc; border: 1px solid #e2e8f0; border-left: 3px solid #475569; padding: 6px 10px; }
.kpi-label { font-size: 7.5px; color: #64748b; text-transform: uppercase; letter-spacing:.06em; font-weight: 600; }
.kpi-value { font-size: 13px; font-weight: bold; color: #1e293b; margin-top: 1px; }
table.main { width: 100%; border-collapse: collapse; margin-top: 2px; }
table.main th { background: #475569; color: white; padding: 6px 7px; font-size: 10px; font-weight: 600; border: 1px solid #334155; letter-spacing:.02em }
table.main td { padding: 5px 7px; font-size: 9px; border: 1px solid #e2e8f0; }
.left { text-align: left; } .right { text-align: right; } .center { text-align: center; }
.bold { font-weight: bold; } .slate { color: #475569; } .green { color: #15803d; }
.tot-row td { background: #475569 !important; color: white !important; font-weight: bold; font-size: 10px; padding: 7px; border: 1px solid #334155; }
.footer { margin-top: 12px; font-size: 8px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 5px; text-align: center; }
</style></head><body>
<table class="header-table"><tr>
  <td class="logo-cell">' . $logoTag . '</td>
  <td class="title-cell">
    <div class="doc-title">Archivio Offerte</div>
    <div class="doc-sub">Estratto il ' . date('d/m/Y H:i') . ' &nbsp;·&nbsp; ' . count($lista) . ' offerte</div>
  </td>
</tr></table>
' . $kpiBox . '
<table class="main">
  <thead><tr>
    <th class="left">Commessa</th>
    <th class="left">Cliente</th>
    <th class="center">Data</th>
    <th class="center">N. Odoo</th>
    ' . ($showUtente ? '<th class="center">Utente</th>' : '') . '
    <th class="right">Ricavi €</th>
    <th class="right">Costi €</th>
    <th class="right">Margine €</th>
    <th class="right">Margine %</th>
  </tr></thead>
  <tbody>' . $rows . '</tbody>
  <tfoot>' . $totRow . '</tfoot>
</table>
<div class="footer">BP Template &nbsp;·&nbsp; Ecotel Italia &nbsp;·&nbsp; Documento generato automaticamente</div>
</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultPaperSize', 'A4');
    $options->set('defaultPaperOrientation', 'landscape');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->render();
    return $dompdf->output();
}
