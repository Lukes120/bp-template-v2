<?php
/**
 * Test parità bp_calc_all (PHP) ↔ calcAll (JS).
 *
 * Esecuzione: php tests/calcoli.test.php (dalla root del progetto)
 * Exit code 0 = tutti passati, 1 = almeno uno fallito.
 *
 * Fixture coprono: offerta vuota, offerta con valori tipici, sconto direzione approvato,
 * prezzo imposto approvato, margine sotto soglia rosso. Mantenere in sync con la suite
 * JS analoga (se mai aggiunta) per evitare drift tra le due implementazioni.
 */

require_once __DIR__ . '/../core/calcoli.php';

$tests   = 0;
$failed  = 0;

function assertEq(string $name, $expected, $got, float $tol = 0.01): void {
    global $tests, $failed;
    $tests++;
    $ok = is_float($expected) || is_float($got)
        ? abs((float)$expected - (float)$got) <= $tol
        : $expected === $got;
    if ($ok) {
        echo "  OK  | $name\n";
    } else {
        $failed++;
        echo "  FAIL| $name | atteso=" . var_export($expected, true) . " ottenuto=" . var_export($got, true) . "\n";
    }
}

function fixtureBase(): array {
    return [
        'speseGenerali' => '5',
        'scontoStato'   => '',
        'scontoTipo'    => 'pct',
        'scontoValore'  => 0,
        'prezzoImpostoAttivo' => false,
        'prezzoImpostoValore' => 0,
        'personale'    => [],
        'materiali'    => [],
        'servizi'      => [],
        'manutenzione' => [],
        'trasferte'    => [],
    ];
}

/* ======================== T1: offerta vuota ======================== */
echo "[T1] offerta vuota\n";
$r = bp_calc_all(fixtureBase());
assertEq("T1 tC=0",   0.0, $r['tC']);
assertEq("T1 tVL=0",  0.0, $r['tVL']);
assertEq("T1 tF=0",   0.0, $r['tF']);
assertEq("T1 mE=0",   0.0, $r['mE']);
assertEq("T1 mP=0",   0.0, $r['mP']);

/* ======================== T2: 1 riga personale + 1 materiale ======================== */
echo "\n[T2] personale 8h x 20 €/h markup 35% + materiali 10 x 5 € markup 25%\n";
$f = fixtureBase();
$f['personale'] = [
    ['id' => 'p1', 'categoria' => 'C', 'oreG' => 8,  'costoH' => 20, 'markup' => 35],
];
$f['materiali'] = [
    ['id' => 'm1', 'desc' => 'cavo', 'qta' => 10, 'costoU' => 5, 'markup' => 25],
];
$r = bp_calc_all($f);
// Personale: base=8*20=160, pv=160*1.35=216
// Materiali: base=10*5=50, pv=50*1.25=62.5
// tC=210, tVL=278.5, sg=210*0.05=10.5, tF=289
// mE=tF-tC=79, mP=79/289=27.34%
assertEq("T2 tCP=160",  160.0,  $r['tCP']);
assertEq("T2 tVP=216",  216.0,  $r['tVP']);
assertEq("T2 tCM=50",   50.0,   $r['tCM']);
assertEq("T2 tVM=62.5", 62.5,   $r['tVM']);
assertEq("T2 tC=210",   210.0,  $r['tC']);
assertEq("T2 tVL=278.5",278.5,  $r['tVL']);
assertEq("T2 sg=10.5",  10.5,   $r['sg']);
assertEq("T2 tF=289",   289.0,  $r['tF']);
assertEq("T2 mE=79",    79.0,   $r['mE']);
assertEq("T2 mP=27.34", 27.34,  $r['mP'], 0.05);

/* ======================== T3: sconto % approvato ======================== */
echo "\n[T3] T2 + sconto 10% approvato\n";
$f['scontoStato']  = 'approvato';
$f['scontoTipo']   = 'pct';
$f['scontoValore'] = 10;
$r = bp_calc_all($f);
// scontoE = tF*10% = 28.90
// tFCalc = 289 - 28.90 = 260.10
// tFSconto = tFCalc (no PI)
// mE = 260.10 - 210 = 50.10
// mP = 50.10/260.10 = 19.26%
assertEq("T3 scontoE=28.9", 28.9,   $r['scontoE'], 0.01);
assertEq("T3 tFCalc=260.1", 260.1,  $r['tFCalc'], 0.05);
assertEq("T3 tFSconto=260.1",260.1, $r['tFSconto'], 0.05);
assertEq("T3 mP=19.26",     19.26,  $r['mP'], 0.05);

/* ======================== T4: prezzo imposto approvato ======================== */
echo "\n[T4] T2 + prezzo imposto 250 approvato\n";
$f = fixtureBase();
$f['personale']           = [['id' => 'p1', 'categoria' => 'C', 'oreG' => 8, 'costoH' => 20, 'markup' => 35]];
$f['materiali']           = [['id' => 'm1', 'desc' => 'cavo', 'qta' => 10, 'costoU' => 5, 'markup' => 25]];
$f['scontoStato']         = 'approvato';
$f['prezzoImpostoAttivo'] = true;
$f['prezzoImpostoValore'] = 250;
$r = bp_calc_all($f);
// Prezzo imposto sostituisce tFSconto: tFSconto=250
// mE = 250 - 210 = 40
// mP = 40/250 = 16%
// scontoE viene azzerato dal PI approvato
assertEq("T4 prezzoImpostoOk=true", true, $r['prezzoImpostoOk']);
assertEq("T4 scontoE=0",  0.0,   $r['scontoE']);
assertEq("T4 tFSconto=250", 250.0, $r['tFSconto']);
assertEq("T4 mE=40",      40.0,  $r['mE']);
assertEq("T4 mP=16",      16.0,  $r['mP'], 0.01);

/* ======================== T5: margine sotto soglia rossa ======================== */
echo "\n[T5] margine 5% (sotto YELLOW=10 -> dovrebbe essere rosso)\n";
// markup 5% basso → tF poco sopra tC → mP basso
$f = fixtureBase();
$f['personale']      = [['id' => 'p1', 'categoria' => 'C', 'oreG' => 10, 'costoH' => 100, 'markup' => 5]];
$f['speseGenerali']  = '0';
$r = bp_calc_all($f);
// base=1000, pv=1050, tC=1000, tVL=1050, sg=0, tF=1050, mE=50, mP=50/1050=4.76%
assertEq("T5 mP<10 (red)", true, $r['mP'] < BP_MARGINE_YELLOW);
assertEq("T5 mP=4.76",     4.76, $r['mP'], 0.05);

/* ======================== T6: trasferta multi-componente ======================== */
echo "\n[T6] trasferta 2 persone x 3 giorni\n";
$f = fixtureBase();
$f['trasferte'] = [[
    'id' => 't1', 'desc' => 'cantiere',
    'persone' => 2, 'giorni' => 3,
    'costoGiorno' => 100, 'vitto' => 20, 'alloggio' => 50,
    'km' => 300, 'costoKm' => 0.30, 'markup' => 10,
]];
$r = bp_calc_all($f);
// base = 100*2*3 + 20*2*3 + 50*2*3 + 300*0.30 = 600 + 120 + 300 + 90 = 1110
// pv = 1110 * 1.10 = 1221
assertEq("T6 base=1110", 1110.0, $r['tCT']);
assertEq("T6 pv=1221",   1221.0, $r['tVT']);

/* ======================== T7: costanti soglie ======================== */
echo "\n[T7] costanti BP_MARGINE_GREEN/YELLOW definite e coerenti\n";
assertEq("T7 BP_MARGINE_GREEN=20",  20, BP_MARGINE_GREEN);
assertEq("T7 BP_MARGINE_YELLOW=10", 10, BP_MARGINE_YELLOW);

/* ======================== T8: overmarkup = 0 (parità con T2) ======================== */
echo "\n[T8] overmarkup=0 -> identico a T2 (sanity check)\n";
$f = fixtureBase();
$f['personale'] = [['id' => 'p1', 'categoria' => 'C', 'oreG' => 8, 'costoH' => 20, 'markup' => 35]];
$f['materiali'] = [['id' => 'm1', 'desc' => 'cavo', 'qta' => 10, 'costoU' => 5, 'markup' => 25]];
$f['overmarkup'] = 0;
$r = bp_calc_all($f);
assertEq("T8 tVL=278.5",   278.5, $r['tVL']);
assertEq("T8 tVLfinale=278.5", 278.5, $r['tVLfinale']);
assertEq("T8 overmarkupValore=0", 0.0, $r['overmarkupValore']);
assertEq("T8 sg=10.5",     10.5,  $r['sg']);
assertEq("T8 tF=289",      289.0, $r['tF']);
assertEq("T8 mP=27.34",    27.34, $r['mP'], 0.05);

/* ======================== T9: overmarkup = 10% ======================== */
echo "\n[T9] overmarkup=10% su dati di T2\n";
$f['overmarkup'] = 10;
$r = bp_calc_all($f);
// tVL=278.5, overmarkupValore=27.85, tVLfinale=306.35
// sg=10.5 (INVARIATO: dipende solo da tC)
// tF=316.85, mE=106.85, mP=106.85/316.85=33.72%
assertEq("T9 overmarkup=10",       10,     $r['overmarkup']);
assertEq("T9 overmarkupValore=27.85", 27.85, $r['overmarkupValore'], 0.01);
assertEq("T9 tVLfinale=306.35",    306.35, $r['tVLfinale'], 0.01);
assertEq("T9 sg=10.5 (invariato)", 10.5,   $r['sg']);
assertEq("T9 tF=316.85",           316.85, $r['tF'], 0.01);
assertEq("T9 mE=106.85",           106.85, $r['mE'], 0.01);
assertEq("T9 mP=33.72",            33.72,  $r['mP'], 0.05);

/* ======================== T10: overmarkup massimo 30% ======================== */
echo "\n[T10] overmarkup=30% (massimo del range)\n";
$f['overmarkup'] = 30;
$r = bp_calc_all($f);
// overmarkupValore=278.5*0.30=83.55, tVLfinale=362.05
// sg=10.5, tF=372.55, mE=162.55, mP=43.63%
assertEq("T10 overmarkupValore=83.55", 83.55, $r['overmarkupValore'], 0.01);
assertEq("T10 tVLfinale=362.05",       362.05, $r['tVLfinale'], 0.01);
assertEq("T10 sg=10.5 (invariato)",    10.5,   $r['sg']);
assertEq("T10 tF=372.55",              372.55, $r['tF'], 0.01);
assertEq("T10 mP=43.63",               43.63,  $r['mP'], 0.05);

/* ======================== T11: overmarkup + sconto direzione approvato ======================== */
echo "\n[T11] overmarkup=10% + sconto direzione 10% approvato\n";
$f['overmarkup'] = 10;
$f['scontoStato']  = 'approvato';
$f['scontoTipo']   = 'pct';
$f['scontoValore'] = 10;
$r = bp_calc_all($f);
// tF post-overmarkup = 316.85
// scontoE = 316.85 * 0.10 = 31.685
// tFCalc = 316.85 - 31.685 = 285.165
// mE = 285.165 - 210 = 75.165
// mP = 75.165/285.165 = 26.36%
assertEq("T11 tF=316.85",     316.85, $r['tF'],      0.01);
assertEq("T11 scontoE=31.685",31.685, $r['scontoE'], 0.02);
assertEq("T11 tFCalc=285.17", 285.165,$r['tFCalc'],  0.02);
assertEq("T11 mP=26.36",      26.36,  $r['mP'],      0.05);

/* ======================== Riassunto ======================== */
echo "\n=========================================\n";
echo "Test eseguiti: $tests | Falliti: $failed\n";
exit($failed > 0 ? 1 : 0);
