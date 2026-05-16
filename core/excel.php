<?php
/**
 * Generazione XLSX (singola offerta + archivio).
 * Usa PhpSpreadsheet, condiviso tra api/genera_excel.php, api/archivio_excel.php, api/allega_odoo.php.
 */

require_once __DIR__ . '/calcoli.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

function bp_xlsx_offerta(array $form, ?string $utente = null): string {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Valutazione Commessa');

    $titleStyle = ['font'=>['bold'=>true,'size'=>14,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF475569']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]];
    $secStyle   = ['font'=>['bold'=>true,'size'=>11,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF475569']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]];
    $hdrStyle   = ['font'=>['bold'=>true,'size'=>9,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF64748B']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFCCCCCC']]]];
    $rowStyle   = ['font'=>['size'=>10],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFCCCCCC']]]];
    $rowAltStyle= ['font'=>['size'=>10],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFF0F4F9']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFCCCCCC']]]];
    $totStyle   = ['font'=>['bold'=>true,'size'=>10,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF64748B']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF64748B']]]];
    $grandStyle = ['font'=>['bold'=>true,'size'=>13,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF475569']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_MEDIUM,'color'=>['argb'=>'FF475569']]]];
    $infoLabelStyle = ['font'=>['bold'=>true,'size'=>10,'color'=>['argb'=>'FF475569']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT]];
    $infoValueStyle = ['font'=>['size'=>10],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT]];
    $numFmt = '#,##0.00'; $pctFmt = '0.00"%"';

    $sheet->mergeCells('A1:L1');
    $sheet->setCellValue('A1', 'VALUTAZIONE COMMESSA - ' . strtoupper($form['nome'] ?? ''));
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    $sheet->getRowDimension(1)->setRowHeight(34);
    $sheet->getRowDimension(2)->setRowHeight(6);

    $infoRows = [
        ['Commessa:', $form['nome'] ?? '',         'Cliente:', $form['cliente'] ?? ''],
        ['Tipo:',     $form['tipo'] ?? '',         'Data:',    $form['data']    ?? ''],
        ['N. Odoo:',  $form['nOrdineOdoo'] ?? '',  'Note:',    $form['note']    ?? ''],
    ];
    $r = 3;
    foreach ($infoRows as $ir) {
        $sheet->setCellValue('A' . $r, $ir[0]); $sheet->getStyle('A' . $r)->applyFromArray($infoLabelStyle);
        $sheet->mergeCells('B' . $r . ':D' . $r); $sheet->setCellValue('B' . $r, $ir[1]); $sheet->getStyle('B' . $r)->applyFromArray($infoValueStyle);
        $sheet->setCellValue('F' . $r, $ir[2]); $sheet->getStyle('F' . $r)->applyFromArray($infoLabelStyle);
        $sheet->mergeCells('G' . $r . ':J' . $r); $sheet->setCellValue('G' . $r, $ir[3]); $sheet->getStyle('G' . $r)->applyFromArray($infoValueStyle);
        $sheet->getRowDimension($r)->setRowHeight(16); $r++;
    }
    $r++;
    $sectionTotRows = [];

    // MANODOPERA
    $sheet->mergeCells('A' . $r . ':H' . $r);
    $sheet->setCellValue('A' . $r, 'MANODOPERA');
    $sheet->getStyle('A' . $r)->applyFromArray($secStyle);
    $sheet->getRowDimension($r)->setRowHeight(20); $r++;
    foreach (['Categoria','h/uomo','Costo/h','Costo Tot.','Markup %','Prezzo Vendita','Margine'] as $i => $h) {
        $sheet->setCellValue(['A','B','C','D','E','F','G'][$i] . $r, $h);
    }
    $sheet->getStyle('A' . $r . ':H' . $r)->applyFromArray($hdrStyle);
    $sheet->getRowDimension($r)->setRowHeight(16); $r++;
    $fdr = $r;
    $personaleF = array_values(array_filter($form['personale'] ?? [], fn($p) => bp_pf($p['oreG'] ?? 0) * bp_pf($p['costoH'] ?? 0) > 0));
    foreach ($personaleF as $idx => $p) {
        $st = ($idx % 2 === 0) ? $rowStyle : $rowAltStyle;
        $sheet->setCellValue('A' . $r, $p['categoria'] ?? '');
        $sheet->setCellValue('B' . $r, bp_pf($p['oreG']));
        $sheet->setCellValue('C' . $r, bp_pf($p['costoH']));
        $sheet->setCellValue('D' . $r, '=B' . $r . '*C' . $r);
        $sheet->setCellValue('E' . $r, bp_pf($p['markup']));
        $sheet->setCellValue('F' . $r, '=D' . $r . '*(1+E' . $r . '/100)');
        $sheet->setCellValue('G' . $r, '=F' . $r . '-D' . $r);
        $sheet->getStyle('A' . $r . ':G' . $r)->applyFromArray($st);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('B' . $r . ':G' . $r)->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getStyle('E' . $r)->getNumberFormat()->setFormatCode($pctFmt);
        $sheet->getStyle('A' . $r)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($r)->setRowHeight(20); $r++;
    }
    $ldr = $r - 1;
    $sheet->setCellValue('A' . $r, 'TOTALE MANODOPERA');
    $sheet->mergeCells('A' . $r . ':C' . $r);
    if ($ldr >= $fdr) {
        $sheet->setCellValue('D' . $r, '=SUM(D' . $fdr . ':D' . $ldr . ')');
        $sheet->setCellValue('F' . $r, '=SUM(F' . $fdr . ':F' . $ldr . ')');
        $sheet->setCellValue('G' . $r, '=SUM(G' . $fdr . ':G' . $ldr . ')');
    } else {
        $sheet->setCellValue('D' . $r, 0);
        $sheet->setCellValue('F' . $r, 0);
        $sheet->setCellValue('G' . $r, 0);
    }
    $sheet->getStyle('A' . $r . ':G' . $r)->applyFromArray($totStyle);
    $sheet->getStyle('F' . $r . ':G' . $r)->getNumberFormat()->setFormatCode($numFmt);
    $sectionTotRows['manodopera'] = ['costo' => 'D' . $r, 'vendita' => 'F' . $r];
    $sheet->getRowDimension($r)->setRowHeight(18); $r += 2;

    // MATERIALI / SERVIZI / MANUTENZIONE
    foreach ([['MATERIALI','materiali'], ['SERVIZI E SUBAPPALTI','servizi'], ['MANUTENZIONE','manutenzione']] as [$titolo, $key]) {
        $sheet->mergeCells('A' . $r . ':G' . $r);
        $sheet->setCellValue('A' . $r, $titolo);
        $sheet->getStyle('A' . $r)->applyFromArray($secStyle);
        $sheet->getRowDimension($r)->setRowHeight(20); $r++;
        foreach (['Descrizione','Qta','Costo Unit.','Costo Tot.','Markup %','Prezzo Vendita','Margine'] as $i => $h) {
            $sheet->setCellValue(['A','B','C','D','E','F','G'][$i] . $r, $h);
        }
        $sheet->getStyle('A' . $r . ':G' . $r)->applyFromArray($hdrStyle);
        $sheet->getRowDimension($r)->setRowHeight(16); $r++;
        $fdr = $r;
        $rowsF = array_values(array_filter($form[$key] ?? [], fn($m) => bp_pf($m['qta'] ?? 0) * bp_pf($m['costoU'] ?? 0) > 0));
        foreach ($rowsF as $idx => $m) {
            $st = ($idx % 2 === 0) ? $rowStyle : $rowAltStyle;
            $sheet->setCellValue('A' . $r, $m['desc'] ?? '');
            $sheet->setCellValue('B' . $r, bp_pf($m['qta']));
            $sheet->setCellValue('C' . $r, bp_pf($m['costoU']));
            $sheet->setCellValue('D' . $r, '=B' . $r . '*C' . $r);
            $sheet->setCellValue('E' . $r, bp_pf($m['markup']));
            $sheet->setCellValue('F' . $r, '=D' . $r . '*(1+E' . $r . '/100)');
            $sheet->setCellValue('G' . $r, '=F' . $r . '-D' . $r);
            $sheet->getStyle('A' . $r . ':G' . $r)->applyFromArray($st);
            $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('B' . $r . ':G' . $r)->getNumberFormat()->setFormatCode($numFmt);
            $sheet->getStyle('E' . $r)->getNumberFormat()->setFormatCode($pctFmt);
            $sheet->getStyle('A' . $r)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($r)->setRowHeight(20); $r++;
        }
        $ldr = $r - 1;
        $sheet->setCellValue('A' . $r, 'TOTALE ' . $titolo);
        $sheet->mergeCells('A' . $r . ':C' . $r);
        if ($ldr >= $fdr) {
            $sheet->setCellValue('D' . $r, '=SUM(D' . $fdr . ':D' . $ldr . ')');
            $sheet->setCellValue('F' . $r, '=SUM(F' . $fdr . ':F' . $ldr . ')');
            $sheet->setCellValue('G' . $r, '=F' . $r . '-D' . $r);
        } else {
            $sheet->setCellValue('D' . $r, 0);
            $sheet->setCellValue('F' . $r, 0);
            $sheet->setCellValue('G' . $r, 0);
        }
        $sheet->getStyle('A' . $r . ':G' . $r)->applyFromArray($totStyle);
        $sheet->getStyle('D' . $r . ':G' . $r)->getNumberFormat()->setFormatCode($numFmt);
        $sectionTotRows[$key] = ['costo' => 'D' . $r, 'vendita' => 'F' . $r];
        $sheet->getRowDimension($r)->setRowHeight(18); $r += 2;
    }

    // TRASFERTE
    $sheet->mergeCells('A' . $r . ':L' . $r);
    $sheet->setCellValue('A' . $r, 'TRASFERTE');
    $sheet->getStyle('A' . $r)->applyFromArray($secStyle);
    $sheet->getRowDimension($r)->setRowHeight(20); $r++;
    foreach (['Descrizione','Persone','Giorni','Costo/g','Vitto','Alloggio','Km','EUR/km','Costo Tot.','Markup %','Prezzo Vendita','Margine'] as $i => $h) {
        $sheet->setCellValue(['A','B','C','D','E','F','G','H','I','J','K','L'][$i] . $r, $h);
    }
    $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($hdrStyle);
    $sheet->getRowDimension($r)->setRowHeight(16); $r++;
    $fdr = $r;
    $trasferteF = array_values(array_filter($form['trasferte'] ?? [], function($t) {
        $b = (bp_pf($t['costoGiorno'] ?? 0) + bp_pf($t['vitto'] ?? 0) + bp_pf($t['alloggio'] ?? 0))
           * bp_pf($t['persone'] ?? 0) * bp_pf($t['giorni'] ?? 0)
           + bp_pf($t['km'] ?? 0) * bp_pf($t['costoKm'] ?? 0);
        return $b > 0;
    }));
    foreach ($trasferteF as $idx => $t) {
        $st = ($idx % 2 === 0) ? $rowStyle : $rowAltStyle;
        $sheet->setCellValue('A' . $r, $t['desc'] ?? '');
        $sheet->setCellValue('B' . $r, bp_pf($t['persone']));
        $sheet->setCellValue('C' . $r, bp_pf($t['giorni']));
        $sheet->setCellValue('D' . $r, bp_pf($t['costoGiorno']));
        $sheet->setCellValue('E' . $r, bp_pf($t['vitto']));
        $sheet->setCellValue('F' . $r, bp_pf($t['alloggio']));
        $sheet->setCellValue('G' . $r, bp_pf($t['km']));
        $sheet->setCellValue('H' . $r, bp_pf($t['costoKm']));
        $sheet->setCellValue('I' . $r, '=(D' . $r . '+E' . $r . '+F' . $r . ')*B' . $r . '*C' . $r . '+G' . $r . '*H' . $r);
        $sheet->setCellValue('J' . $r, bp_pf($t['markup']));
        $sheet->setCellValue('K' . $r, '=I' . $r . '*(1+J' . $r . '/100)');
        $sheet->setCellValue('L' . $r, '=K' . $r . '-I' . $r);
        $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($st);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('B' . $r . ':L' . $r)->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getStyle('J' . $r)->getNumberFormat()->setFormatCode($pctFmt);
        $sheet->getStyle('A' . $r)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($r)->setRowHeight(20); $r++;
    }
    $ldr = $r - 1;
    $sheet->setCellValue('A' . $r, 'TOTALE TRASFERTE');
    $sheet->mergeCells('A' . $r . ':H' . $r);
    if ($ldr >= $fdr) {
        $sheet->setCellValue('I' . $r, '=SUM(I' . $fdr . ':I' . $ldr . ')');
        $sheet->setCellValue('K' . $r, '=SUM(K' . $fdr . ':K' . $ldr . ')');
        $sheet->setCellValue('L' . $r, '=K' . $r . '-I' . $r);
    } else {
        $sheet->setCellValue('I' . $r, 0);
        $sheet->setCellValue('K' . $r, 0);
        $sheet->setCellValue('L' . $r, 0);
    }
    $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($totStyle);
    $sheet->getStyle('I' . $r . ':L' . $r)->getNumberFormat()->setFormatCode($numFmt);
    $sectionTotRows['trasferte'] = ['costo' => 'I' . $r, 'vendita' => 'K' . $r];
    $sheet->getRowDimension($r)->setRowHeight(18); $r += 2;

    // RIEPILOGO FINALE
    $sheet->mergeCells('A' . $r . ':L' . $r);
    $sheet->setCellValue('A' . $r, 'RIEPILOGO FINALE');
    $sheet->getStyle('A' . $r)->applyFromArray($secStyle);
    $sheet->getRowDimension($r)->setRowHeight(22); $r++;

    $sheet->setCellValue('A' . $r, 'Voce'); $sheet->mergeCells('A' . $r . ':H' . $r);
    $sheet->setCellValue('I' . $r, 'Costo'); $sheet->setCellValue('J' . $r, '%');
    $sheet->setCellValue('K' . $r, 'Vendita'); $sheet->setCellValue('L' . $r, 'Margine');
    $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($hdrStyle);
    $sheet->getRowDimension($r)->setRowHeight(16); $r++;

    $riepC = []; $riepV = [];
    foreach ([
        ['Manodopera',           $sectionTotRows['manodopera']],
        ['Materiali',            $sectionTotRows['materiali']],
        ['Servizi e Subappalti', $sectionTotRows['servizi']],
        ['Manutenzione',         $sectionTotRows['manutenzione']],
        ['Trasferte',            $sectionTotRows['trasferte']],
    ] as $idx => $rr) {
        $st = ($idx % 2 === 0) ? $rowStyle : $rowAltStyle;
        $sheet->setCellValue('A' . $r, $rr[0]);
        $sheet->mergeCells('A' . $r . ':H' . $r);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->setCellValue('I' . $r, '=' . $rr[1]['costo']);   $riepC[] = 'I' . $r;
        $sheet->setCellValue('K' . $r, '=' . $rr[1]['vendita']); $riepV[] = 'K' . $r;
        $sheet->setCellValue('L' . $r, '=K' . $r . '-I' . $r);
        $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($st);
        $sheet->getStyle('I' . $r)->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getStyle('K' . $r . ':L' . $r)->getNumberFormat()->setFormatCode($numFmt);
        $r++;
    }

    $sheet->setCellValue('A' . $r, 'TOTALE COSTI DIRETTI');
    $sheet->mergeCells('A' . $r . ':H' . $r);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->setCellValue('I' . $r, '=' . implode('+', $riepC)); $totC = 'I' . $r;
    $sheet->setCellValue('K' . $r, '=' . implode('+', $riepV)); $totV = 'K' . $r;
    $sheet->setCellValue('L' . $r, '=K' . $r . '-I' . $r);
    $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($totStyle);
    $sheet->getStyle('I' . $r . ':L' . $r)->getNumberFormat()->setFormatCode($numFmt);
    $sheet->getRowDimension($r)->setRowHeight(18); $r++;

    $sgPct = bp_pf($form['speseGenerali'] ?? 5);
    $sheet->setCellValue('A' . $r, 'Spese Generali (' . $sgPct . '%)');
    $sheet->mergeCells('A' . $r . ':H' . $r);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sgCell = 'K' . $r;
    $sheet->setCellValue($sgCell, '=' . $totV . '*(' . $sgPct . '/100)');
    $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($rowAltStyle);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    $sheet->getStyle($sgCell)->getNumberFormat()->setFormatCode($numFmt);
    $sheet->getRowDimension($r)->setRowHeight(16); $r++;

    $prezzoImpostoOk = !empty($form['prezzoImpostoAttivo']) && (($form['scontoStato'] ?? '') === 'approvato');
    $scontoApp = !$prezzoImpostoOk && ($form['scontoStato'] ?? '') === 'approvato' ? bp_pf($form['scontoValore'] ?? 0) : 0;
    $tVSum = 0;
    foreach ($form['personale'] ?? [] as $rr) {
        $b = bp_pf($rr['oreG']) * bp_pf($rr['costoH']);
        $tVSum += $b * (1 + bp_pf($rr['markup']) / 100);
    }
    foreach (array_merge($form['materiali'] ?? [], $form['servizi'] ?? [], $form['manutenzione'] ?? []) as $rr) {
        $b = bp_pf($rr['qta']) * bp_pf($rr['costoU']);
        $tVSum += $b * (1 + bp_pf($rr['markup']) / 100);
    }
    foreach ($form['trasferte'] ?? [] as $rr) {
        $p = bp_pf($rr['persone']); $g = bp_pf($rr['giorni']);
        $b = bp_pf($rr['costoGiorno']) * $p * $g + bp_pf($rr['vitto']) * $p * $g + bp_pf($rr['alloggio']) * $p * $g + bp_pf($rr['km']) * bp_pf($rr['costoKm']);
        $tVSum += $b * (1 + bp_pf($rr['markup']) / 100);
    }
    $tFCalc = $tVSum * (1 + $sgPct / 100);

    // Sconto Direzione: solo se prezzo imposto NON attivo e c'è uno sconto effettivo
    if (!$prezzoImpostoOk && $scontoApp > 0) {
        $scontoE = ($form['scontoTipo'] ?? 'pct') === 'pct' ? $tFCalc * ($scontoApp / 100) : $scontoApp;
        $scontoLabel = ($form['scontoTipo'] ?? 'pct') === 'pct'
            ? $scontoApp . '%'
            : 'EUR ' . number_format($scontoApp, 2, ',', '.');
        $sheet->setCellValue('A' . $r, 'Sconto Direzione (' . $scontoLabel . ')');
        $sheet->mergeCells('A' . $r . ':H' . $r);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $scontoCell = 'K' . $r;
        $sheet->setCellValue($scontoCell, -$scontoE);
        $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($rowAltStyle);
        $sheet->getStyle('A' . $r)->getFont()->setBold(true);
        $sheet->getStyle('A' . $r)->getFont()->getColor()->setARGB('FFDC2626');
        $sheet->getStyle($scontoCell)->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getRowDimension($r)->setRowHeight(16); $r++;
        $totCalcFormula = '=' . $totV . '+' . $sgCell . '+' . $scontoCell;
    } else {
        $totCalcFormula = '=' . $totV . '+' . $sgCell;
    }

    // TOTALE CALCOLATO (sempre presente). Quando prezzo imposto attivo, è il riferimento di base ma non il prezzo praticato.
    $sheet->setCellValue('A' . $r, 'TOTALE CALCOLATO (IVA ESCLUSA)');
    $sheet->mergeCells('A' . $r . ':H' . $r);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->setCellValue('K' . $r, $totCalcFormula);
    $sheet->setCellValue('L' . $r, '=K' . $r . '-' . $totC);
    $totCalc = 'K' . $r;
    if ($prezzoImpostoOk) {
        // riga calcolato in grigio chiaro (non è il prezzo finale)
        $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($totStyle);
        $sheet->getStyle('A' . $r . ':L' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
        $sheet->getStyle('A' . $r . ':L' . $r)->getFont()->getColor()->setARGB('FF4B5563');
    } else {
        $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($grandStyle);
    }
    $sheet->getStyle('K' . $r . ':L' . $r)->getNumberFormat()->setFormatCode($numFmt);
    $sheet->getRowDimension($r)->setRowHeight($prezzoImpostoOk ? 22 : 28); $r++;

    if ($prezzoImpostoOk) {
        $piVal = bp_pf($form['prezzoImpostoValore'] ?? 0);
        $sheet->setCellValue('A' . $r, '◉ PREZZO IMPOSTO (IVA ESCLUSA)');
        $sheet->mergeCells('A' . $r . ':H' . $r);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $piCell = 'K' . $r;
        $sheet->setCellValue($piCell, $piVal);
        $sheet->setCellValue('L' . $r, '=' . $piCell . '-' . $totC);
        $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($grandStyle);
        $sheet->getStyle('A' . $r . ':L' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDE9FE');
        $sheet->getStyle('A' . $r . ':L' . $r)->getFont()->getColor()->setARGB('FF5B21B6');
        $sheet->getStyle($piCell . ':L' . $r)->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getRowDimension($r)->setRowHeight(28);
        $totF = $piCell;
        $r++;
    } else {
        $totF = $totCalc;
    }

    // Margine % finale (sul prezzo realmente praticato — imposto se attivo, calcolato altrimenti)
    $sheet->setCellValue('A' . $r, 'Margine %');
    $sheet->mergeCells('A' . $r . ':H' . $r);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->setCellValue('L' . $r, '=IF(' . $totF . '>0,(' . $totF . '-' . $totC . ')/' . $totF . '*100,0)');
    $sheet->getStyle('A' . $r . ':L' . $r)->applyFromArray($rowStyle);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    $sheet->getStyle('L' . $r)->getNumberFormat()->setFormatCode('0.0"%"');
    $sheet->getStyle('A' . $r . ':L' . $r)->getFont()->setSize(12);
    $sheet->getStyle('L' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF475569');
    $sheet->getStyle('L' . $r)->getFont()->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('L' . $r)->getFont()->setBold(true);
    $sheet->getRowDimension($r)->setRowHeight(22);

    foreach (['A'=>38,'B'=>10,'C'=>10,'D'=>13,'E'=>11,'F'=>14,'G'=>13,'H'=>11,'I'=>14,'J'=>11,'K'=>16,'L'=>14] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }
    $sheet->setShowGridLines(false);

    // Conditional formatting sul margine % finale (cella L$r)
    $mpCondGreen = (new Conditional())->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_GREATERTHANOREQUAL)->addCondition(20);
    $mpCondGreen->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF15803D');
    $mpCondGreen->getStyle()->getFont()->getColor()->setARGB('FFFFFFFF');
    $mpCondYellow = (new Conditional())->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_BETWEEN)->addCondition(10)->addCondition(19.99);
    $mpCondYellow->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCA8A04');
    $mpCondYellow->getStyle()->getFont()->getColor()->setARGB('FFFFFFFF');
    $mpCondRed = (new Conditional())->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_LESSTHAN)->addCondition(10);
    $mpCondRed->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDC2626');
    $mpCondRed->getStyle()->getFont()->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('L' . $r)->setConditionalStyles([$mpCondGreen, $mpCondYellow, $mpCondRed]);

    // Page setup: A4 landscape, fit to 1 page width
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4)->setHeader(0.2)->setFooter(0.2);

    // Header/footer di stampa
    $title = 'Valutazione Commessa - ' . ($form['nome'] ?? '');
    $sheet->getHeaderFooter()->setOddHeader('&L&B' . str_replace('&', '&&', $title) . '&R&D');
    $footerLeft = $utente ? 'Elaborato da: ' . str_replace('&', '&&', $utente) : 'BP Template - Ecotel Italia';
    $sheet->getHeaderFooter()->setOddFooter('&L' . $footerLeft . '&CPag. &P di &N&RBP Template');

    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    return ob_get_clean();
}

function bp_xlsx_archivio(array $lista): string {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Archivio Offerte');

    $titleStyle = ['font'=>['bold'=>true,'size'=>14,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF475569']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]];
    $hdrStyle   = ['font'=>['bold'=>true,'size'=>10,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF64748B']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFCCCCCC']]]];
    $rowStyle   = ['font'=>['size'=>9],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFDDDDDD']]]];
    $rowAltStyle= ['font'=>['size'=>9],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFF2F7FC']],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFDDDDDD']]]];
    $totStyle   = ['font'=>['bold'=>true,'size'=>10,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF475569']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER]];
    $numFmt = '#,##0.00'; $pctFmt = '0.0"%"';

    $showUtente = false;
    foreach ($lista as $r) { if (!empty($r['utente'])) { $showUtente = true; break; } }

    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', 'ARCHIVIO OFFERTE - ' . date('d/m/Y H:i'));
    $sheet->getStyle('A1')->applyFromArray($titleStyle);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $headers = ['Commessa','Cliente','Data','N. Odoo'];
    if ($showUtente) $headers[] = 'Utente';
    array_push($headers, 'Ricavi €','Costi €','Margine €','Margine %');
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '3', $h);
        $col++;
    }
    $lastCol = chr(ord('A') + count($headers) - 1);
    $sheet->getStyle('A3:' . $lastCol . '3')->applyFromArray($hdrStyle);

    $r = 4; $totR = 0; $totC = 0; $totM = 0;
    foreach ($lista as $idx => $o) {
        $st = ($idx % 2 === 0) ? $rowStyle : $rowAltStyle;
        $col = 'A';
        $sheet->setCellValue($col++ . $r, $o['nome'] ?? '');
        $sheet->setCellValue($col++ . $r, $o['cliente'] ?? '');
        $sheet->setCellValue($col++ . $r, $o['data'] ?? '');
        $sheet->setCellValue($col++ . $r, $o['nOrdineOdoo'] ?? '');
        if ($showUtente) $sheet->setCellValue($col++ . $r, $o['utente'] ?? '');
        $sheet->setCellValue($col++ . $r, (float)($o['tF'] ?? 0));
        $sheet->setCellValue($col++ . $r, (float)($o['tC'] ?? 0));
        $sheet->setCellValue($col++ . $r, (float)($o['mE'] ?? 0));
        $sheet->setCellValue($col   . $r, (float)($o['mP'] ?? 0));
        $sheet->getStyle('A' . $r . ':' . $lastCol . $r)->applyFromArray($st);
        $totR += (float)($o['tF'] ?? 0); $totC += (float)($o['tC'] ?? 0); $totM += (float)($o['mE'] ?? 0);
        $r++;
    }
    $totMP = $totR > 0 ? round(($totM / $totR) * 100, 2) : 0;
    $colspan = $showUtente ? 5 : 4;
    $sheet->setCellValue('A' . $r, 'TOTALI (' . count($lista) . ' offerte)');
    $sheet->mergeCells('A' . $r . ':' . chr(ord('A') + $colspan - 1) . $r);
    $col = chr(ord('A') + $colspan);
    $sheet->setCellValue($col++ . $r, $totR);
    $sheet->setCellValue($col++ . $r, $totC);
    $sheet->setCellValue($col++ . $r, $totM);
    $sheet->setCellValue($col   . $r, $totMP);
    $sheet->getStyle('A' . $r . ':' . $lastCol . $r)->applyFromArray($totStyle);

    // formati colonna
    $offsetMon = $showUtente ? 'F' : 'E';
    $colsMon = [];
    $base = ord($offsetMon);
    for ($i = 0; $i < 3; $i++) $colsMon[] = chr($base + $i);
    foreach ($colsMon as $cm) $sheet->getStyle($cm . '4:' . $cm . $r)->getNumberFormat()->setFormatCode($numFmt);
    $colP = chr($base + 3);
    $sheet->getStyle($colP . '4:' . $colP . $r)->getNumberFormat()->setFormatCode($pctFmt);

    foreach (['A'=>30,'B'=>30,'C'=>12,'D'=>12,'E'=>16,'F'=>14,'G'=>14,'H'=>14,'I'=>10] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }
    $sheet->setShowGridLines(false);

    // Freeze pane: blocca le prime 3 righe (titolo+spacer+intestazioni) durante lo scroll
    $sheet->freezePane('A4');

    // Auto-filter su tutte le colonne dati (header + righe, escluso il TOTALI)
    if ($r > 4) {
        $sheet->setAutoFilter('A3:' . $lastCol . ($r - 1));
    }

    // Conditional formatting sulla colonna Margine % (semaforo)
    $colP = chr(ord('A') + count($headers) - 1);  // ultima colonna = margine %
    $cfRangeStart = $colP . '4';
    $cfRangeEnd   = $colP . ($r - 1);
    if ($r > 4) {
        $mpCondGreen = (new Conditional())->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_GREATERTHANOREQUAL)->addCondition(20);
        $mpCondGreen->getStyle()->getFont()->getColor()->setARGB('FF15803D');
        $mpCondGreen->getStyle()->getFont()->setBold(true);
        $mpCondYellow = (new Conditional())->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_BETWEEN)->addCondition(10)->addCondition(19.99);
        $mpCondYellow->getStyle()->getFont()->getColor()->setARGB('FFCA8A04');
        $mpCondYellow->getStyle()->getFont()->setBold(true);
        $mpCondRed = (new Conditional())->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_LESSTHAN)->addCondition(10);
        $mpCondRed->getStyle()->getFont()->getColor()->setARGB('FFDC2626');
        $mpCondRed->getStyle()->getFont()->setBold(true);
        for ($rr = 4; $rr < $r; $rr++) {
            $sheet->getStyle($colP . $rr)->setConditionalStyles([$mpCondGreen, $mpCondYellow, $mpCondRed]);
        }
    }

    // Page setup: A4 landscape, fit to 1 page width
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(3, 3);  // intestazione ripetuta su ogni pagina
    $sheet->getHeaderFooter()->setOddHeader('&L&BArchivio Offerte&R&D &T');
    $sheet->getHeaderFooter()->setOddFooter('&LBP Template - Ecotel Italia&CPag. &P di &N&R' . count($lista) . ' offerte');

    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    return ob_get_clean();
}
