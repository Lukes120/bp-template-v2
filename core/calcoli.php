<?php
/**
 * Calcolo costi/ricavi/margini per un'offerta.
 *
 * SOURCE OF TRUTH: questa funzione DEVE rimanere allineata con js/calc.js (calcAll).
 * Cambi alla formula vanno applicati in entrambi i file.
 *
 * Soglie semaforo margine % (allineate a js/calc.js MARGINE_THRESHOLDS):
 *  >= BP_MARGINE_GREEN  -> verde
 *  >= BP_MARGINE_YELLOW -> giallo
 *  altrimenti           -> rosso
 * Usate da bp_mail_color_margine (mailer.php), conditional formatting Excel, KPI PDF.
 */
const BP_MARGINE_GREEN  = 20;
const BP_MARGINE_YELLOW = 10;

// Soglie minime di markup % per ruolo "user", per sezione. Replica server-side della
// costante MARKUP_MIN_USER in js/calc.js. User puo' alzare ma non scendere sotto.
// Per supervisore/admin: nessun limite.
const BP_MARKUP_MIN_USER = [
    'personale'    => 35,
    'materiali'    => 25,
    'servizi'      => 20,
    'manutenzione' => 25,
    'trasferte'    => 10,
];

function bp_pf($v): float {
    return (float)($v ?? 0);
}

function bp_fmt($n): string {
    return number_format((float)$n, 2, ',', '.');
}

function bp_fmt_pct($n): string {
    return number_format((float)$n, 1, ',', '.');
}

function bp_calc_all(array $f): array {
    $cp = array_map(function($r){
        $b = bp_pf($r['oreG']) * bp_pf($r['costoH']);
        return array_merge($r, ['b' => $b, 'pv' => $b * (1 + bp_pf($r['markup']) / 100)]);
    }, $f['personale'] ?? []);

    $cm = array_map(function($r){
        $b = bp_pf($r['qta']) * bp_pf($r['costoU']);
        return array_merge($r, ['b' => $b, 'pv' => $b * (1 + bp_pf($r['markup']) / 100)]);
    }, $f['materiali'] ?? []);

    $cs = array_map(function($r){
        $b = bp_pf($r['qta']) * bp_pf($r['costoU']);
        return array_merge($r, ['b' => $b, 'pv' => $b * (1 + bp_pf($r['markup']) / 100)]);
    }, $f['servizi'] ?? []);

    $cm2 = array_map(function($r){
        $b = bp_pf($r['qta']) * bp_pf($r['costoU']);
        return array_merge($r, ['b' => $b, 'pv' => $b * (1 + bp_pf($r['markup']) / 100)]);
    }, $f['manutenzione'] ?? []);

    $ct = array_map(function($r){
        $p = bp_pf($r['persone']);
        $g = bp_pf($r['giorni']);
        $b = bp_pf($r['costoGiorno']) * $p * $g
           + bp_pf($r['vitto']) * $p * $g
           + bp_pf($r['alloggio']) * $p * $g
           + bp_pf($r['km']) * bp_pf($r['costoKm']);
        return array_merge($r, ['b' => $b, 'pv' => $b * (1 + bp_pf($r['markup']) / 100)]);
    }, $f['trasferte'] ?? []);

    $sum = function($a, $k){
        return array_reduce($a, function($t, $r) use ($k){ return $t + $r[$k]; }, 0);
    };

    $tCP = $sum($cp, 'b');  $tVP = $sum($cp, 'pv');
    $tCM = $sum($cm, 'b');  $tVM = $sum($cm, 'pv');
    $tCS = $sum($cs, 'b');  $tVS = $sum($cs, 'pv');
    $tCM2 = $sum($cm2, 'b'); $tVM2 = $sum($cm2, 'pv');
    $tCT = $sum($ct, 'b');  $tVT = $sum($ct, 'pv');

    $tC  = $tCP + $tCM + $tCS + $tCM2 + $tCT;
    $tVL = $tVP + $tVM + $tVS + $tVM2 + $tVT;
    $sg  = $tC * (bp_pf($f['speseGenerali'] ?? 5) / 100);
    // Overmarkup (0..30, step 5): maggiorazione sui ricavi che NON tocca le spese generali.
    // tVL_finale = tVL * (1 + overmarkup/100). Vedi anche calcAll in js/calc.js.
    $overmarkup       = (int)($f['overmarkup'] ?? 0);
    $overmarkupValore = $tVL * ($overmarkup / 100);
    $tVLfinale        = $tVL + $overmarkupValore;
    $tF  = $tVLfinale + $sg;

    $scontoApp = ($f['scontoStato'] ?? '') === 'approvato' ? bp_pf($f['scontoValore'] ?? 0) : 0;
    $scontoE   = ($f['scontoTipo'] ?? 'pct') === 'pct' ? $tF * ($scontoApp / 100) : $scontoApp;
    // Totale "calcolato": tF al netto dell'eventuale sconto direzione approvato (ignora prezzo imposto).
    $tFCalc    = $tF - $scontoE;
    $mECalc    = $tFCalc - $tC;
    $mPCalc    = $tFCalc > 0 ? ($mECalc / $tFCalc) * 100 : 0;
    // Modalità "prezzo imposto": se attiva e approvata, sostituisce il totale post-sconto come prezzo praticato.
    $prezzoImpostoOk = !empty($f['prezzoImpostoAttivo']) && (($f['scontoStato'] ?? '') === 'approvato');
    $tFSconto  = $prezzoImpostoOk ? bp_pf($f['prezzoImpostoValore'] ?? 0) : $tFCalc;
    if ($prezzoImpostoOk) { $scontoE = 0; }
    $mE        = $tFSconto - $tC;
    $mP        = $tFSconto > 0 ? ($mE / $tFSconto) * 100 : 0;

    return compact('cp','cm','cs','cm2','ct',
                   'tCP','tVP','tCM','tVM','tCS','tVS','tCM2','tVM2','tCT','tVT',
                   'tC','tVL','tVLfinale','overmarkup','overmarkupValore',
                   'sg','tF','tFCalc','mECalc','mPCalc','tFSconto','scontoE','mE','mP','prezzoImpostoOk');
}
