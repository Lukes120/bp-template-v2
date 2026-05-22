<?php
require_once __DIR__ . '/../core/bootstrap.php';
bp_cors_json();

$method = $_SERVER['REQUEST_METHOD'];
$me     = bp_require_auth();
$actor  = $me;

// Helper: utenti con ruolo 'admin', 'supervisore' o 'viewer' vedono tutte le offerte.
// 'viewer' e' un ruolo read-only sopra-utenti (vede tutto, NON approva, NON elimina altrui).
// Gli 'user' vedono e modificano solo le proprie offerte.
$canSeeAll = in_array($me['ruolo'], ['admin', 'supervisore', 'viewer'], true);
// Chi puo' MODIFICARE offerte di chiunque: solo admin/supervisore (NON viewer).
$canWriteAll = in_array($me['ruolo'], ['admin', 'supervisore'], true);

if ($method === 'GET') {
    $lista = bp_offerte_all();
    if (!$canSeeAll) {
        $lista = array_values(array_filter($lista, function($o) use ($me) {
            return ($o['userId'] ?? '') === $me['id'];
        }));
    }
    bp_json_out($lista);
}

if ($method === 'POST') {
    bp_require_csrf();
    $d = bp_json_input();
    if (empty($d['id']) || empty($d['nome'])) {
        bp_json_out(['error' => 'id e nome obbligatori'], 400);
    }

    // Verifica permessi su offerta esistente: solo owner o admin/supervisore (NO viewer)
    $stmt = bp_db()->prepare("SELECT user_id FROM offerte WHERE id = :id");
    $stmt->execute(['id' => $d['id']]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (!$canWriteAll && ($existing['user_id'] ?? '') !== $me['id']) {
            bp_audit('forbidden', 'offerte', $d['id'], 'tentativo modifica offerta altrui');
            bp_json_out(['error' => 'Non puoi modificare offerte di altri utenti'], 403);
        }
    } else {
        // Nuova offerta: l'utente non admin/sup non può falsificare userId
        if (!$canWriteAll) {
            $d['userId']   = $me['id'];
            $d['userName'] = $me['nome'];
        }
    }

    // I markup sono modificabili solo da admin/supervisore.
    // Per ruolo "user": righe nuove → default; righe esistenti → markup originale dal DB.
    // Stesso flusso recupera il payload originale per S9 (whitelist anti mass-assignment).
    $payloadOld = [];
    if ($existing) {
        $row2 = bp_db()->prepare("SELECT payload_json FROM offerte WHERE id = :id");
        $row2->execute(['id' => $d['id']]);
        $payloadOld = json_decode(($row2->fetch()['payload_json'] ?? '{}'), true) ?: [];
    }
    if ($me['ruolo'] === 'user') {
        $defaultMk = ['personale' => 35, 'materiali' => 25, 'servizi' => 20, 'manutenzione' => 25, 'trasferte' => 10];
        $existingByIdSec = [];
        foreach (array_keys($defaultMk) as $sec) {
            if (!empty($payloadOld[$sec]) && is_array($payloadOld[$sec])) {
                foreach ($payloadOld[$sec] as $r) {
                    if (!empty($r['id'])) {
                        $existingByIdSec[$sec][$r['id']] = $r['markup'] ?? $defaultMk[$sec];
                    }
                }
            }
        }
        foreach ($defaultMk as $sec => $def) {
            if (!empty($d[$sec]) && is_array($d[$sec])) {
                foreach ($d[$sec] as &$r) {
                    $rid = $r['id'] ?? '';
                    $r['markup'] = $existingByIdSec[$sec][$rid] ?? $def;
                }
                unset($r);
            }
        }
        // Spese generali: stessa logica dei markup di sezione.
        // User non può modificarle: ripristino dal DB se l'offerta esiste, altrimenti default 5%.
        if ($existing && isset($payloadOld['speseGenerali'])) {
            $d['speseGenerali'] = $payloadOld['speseGenerali'];
        } else {
            $d['speseGenerali'] = '5';
        }
    }

    // S9 — whitelist anti mass-assignment per ruolo "user".
    // Campi sensibili (allegataOdoo, prezzoImpostoAttivo, prezzoImpostoValore, scontoStato in alcune transizioni)
    // NON devono essere modificabili da un user via payload manomesso. Riprendo i valori dal DB se l'offerta esiste.
    if ($me['ruolo'] === 'user') {
        // L'utente NON può mai falsificare il timestamp di allegato Odoo
        if (array_key_exists('allegataOdoo', $d)) {
            $d['allegataOdoo'] = $payloadOld['allegataOdoo'] ?? null;
        }
        // Stato approvazione PI: user può solo passare a "" (default), "" → "inattesa" via UI dedicata.
        // NON può saltare in "approvato"/"rifiutato"; se ci prova, il backend forza il valore originale.
        if ($existing) {
            $stato = $d['scontoStato'] ?? '';
            $statoVecchio = $payloadOld['scontoStato'] ?? '';
            $transizioniLecite = [
                ''           => ['', 'inattesa'],
                'inattesa'   => ['inattesa', ''],
                'approvato'  => ['approvato', '', 'inattesa'],
                'rifiutato'  => ['rifiutato', '', 'inattesa'],
            ];
            $consentiti = $transizioniLecite[$statoVecchio] ?? [''];
            if (!in_array($stato, $consentiti, true)) {
                $d['scontoStato'] = $statoVecchio;
            }
        }
    }

    // Validazione range numerici (anti-tampering + protezione dati corrotti).
    // Soglie ampie: tolleranti agli inserimenti reali, bloccano valori palesemente sbagliati.
    $errs = [];
    $spese = (float)($d['speseGenerali'] ?? 0);
    if ($spese < 0 || $spese > 100) $errs[] = "Spese generali fuori range (0-100): $spese";
    $sezioni = ['personale', 'materiali', 'servizi', 'manutenzione', 'trasferte'];
    $isUserRole = ($me['ruolo'] ?? '') === 'user';
    foreach ($sezioni as $sec) {
        if (empty($d[$sec]) || !is_array($d[$sec])) continue;
        $sogliaMk = $isUserRole && isset(BP_MARKUP_MIN_USER[$sec]) ? BP_MARKUP_MIN_USER[$sec] : null;
        foreach ($d[$sec] as $i => $r) {
            $mk = (float)($r['markup'] ?? 0);
            if ($mk < 0 || $mk > 500) $errs[] = "Markup $sec[$i] fuori range (0-500): $mk";
            // Anti-tampering: per ruolo "user" il markup di ogni sezione deve essere >= soglia.
            // Server-side replica del clamp client in js/app.js bindEvents. Skip righe con
            // markup=0 (placeholder/vuote: la riga di default ha sempre markup > 0).
            if ($sogliaMk !== null && $mk > 0 && $mk < $sogliaMk) {
                $errs[] = sprintf("Markup %s riga %d (%g%%) sotto soglia ruolo user (%d%%)", $sec, $i + 1, $mk, $sogliaMk);
            }
            foreach (['oreG','costoH','qta','costoU','persone','giorni','costoGiorno','vitto','alloggio','km','costoKm'] as $fld) {
                if (!isset($r[$fld]) || $r[$fld] === '') continue;
                if ((float)$r[$fld] < 0) $errs[] = "$sec[$i].$fld negativo: " . $r[$fld];
            }
        }
    }
    $pi = (float)($d['prezzoImpostoValore'] ?? 0);
    if ($pi < 0) $errs[] = "Prezzo imposto negativo: $pi";
    $sv = (float)($d['scontoValore'] ?? 0);
    if ($sv < 0) $errs[] = "Sconto negativo: $sv";
    if (($d['scontoTipo'] ?? '') === 'pct' && $sv > 100) $errs[] = "Sconto % oltre 100: $sv";
    // Overmarkup: enum {0,5,10,15,20,25,30}. Normalizzo a int e respingo valori fuori set.
    $om = (int)($d['overmarkup'] ?? 0);
    if (!in_array($om, [0,5,10,15,20,25,30], true)) $errs[] = "Overmarkup non valido: $om (ammessi: 0,5,10,15,20,25,30)";
    $d['overmarkup'] = $om;
    if (!empty($errs)) {
        bp_audit('validation_fail', 'offerte', $d['id'], implode('; ', $errs), $me);
        bp_json_out(['error' => 'Dati non validi: ' . $errs[0]], 400);
    }

    // Validazione margine minimo per ruolo — replica server-side di js/app.js:salva().
    // Difesa anti-tampering: senza questo, un user puo' bypassare via DevTools la validazione
    // client (rimuovendo il return del toast) e salvare offerte sotto soglia senza il flow
    // di approvazione sconto direzione. Le stesse 2 regole del client:
    //   1) PI attivo + ruolo user + non approvato -> blocco
    //   2) margine effettivo < soglia ruolo + nessuno sconto approvato -> blocco
    $MARGINE_MIN_RUOLO = ['user' => 15, 'viewer' => 15, 'supervisore' => 5, 'admin' => 0];
    $minMargine = $MARGINE_MIN_RUOLO[$me['ruolo']] ?? 0;
    $statoSc = $d['scontoStato'] ?? '';
    // "approvato" = autorizzato, "inattesa" = utente sta chiedendo approvazione adesso.
    // Entrambi bypassano le regole anti-sotto-soglia: senza questo bypass il flow di
    // "Chiedi approvazione" fallirebbe (catch-22). Vedi bug 21/05/2026 v=63 (Matteo).
    $scontoOk = in_array($statoSc, ['approvato', 'inattesa'], true);
    $piAttivo = !empty($d['prezzoImpostoAttivo']);
    $cValid = bp_calc_all($d);
    $margineOk = $cValid['mP'] >= $minMargine;

    // PI attivo + user: serve approvazione SOLO se margine sotto soglia. Se margine
    // >= soglia ruolo, l'uso di PI non e' un rischio commerciale -> auto-OK senza
    // approvazione. Decisione 21/05/2026 v=65 dopo caso Matteo (PI con margine alto
    // veniva bloccato inutilmente). Regola precedente: blocco a prescindere dal margine.
    if ($piAttivo && !$scontoOk && $me['ruolo'] === 'user' && !$margineOk) {
        $msg = sprintf('PI attivo + margine %.2f%% < soglia %d%% (ruolo user)', $cValid['mP'], $minMargine);
        bp_audit('validation_fail', 'offerte', $d['id'], $msg, $me);
        $mpFmt = number_format($cValid['mP'], 1, ',', '.');
        bp_json_out(['error' => "Prezzo imposto: margine {$mpFmt}% sotto soglia {$minMargine}%, richiede approvazione supervisore."], 400);
    }

    // Regola margine generica per offerte SENZA PI (Sconto Direzione): blocca se margine
    // sotto soglia e nessuna approvazione in flight. Per offerte CON PI la regola sopra
    // copre gia' il caso, qui skippiamo per evitare doppio blocco con messaggi confusi.
    if (!$margineOk && !$scontoOk && !$piAttivo) {
        $msg = sprintf('margine %.2f%% < soglia %d%% (ruolo %s)', $cValid['mP'], $minMargine, $me['ruolo']);
        bp_audit('validation_fail', 'offerte', $d['id'], $msg, $me);
        $mpFmt = number_format($cValid['mP'], 1, ',', '.');
        bp_json_out(['error' => "Margine {$mpFmt}% sotto la soglia minima del {$minMargine}% per il tuo ruolo. Chiedi sconto direzione o rivedi costi/markup."], 400);
    }

    // v=68: se PI attivo + margine OK + stato vuoto, marca scontoStato='approvato'
    // automaticamente. Senza questo, bp_calc_all considera PI non-applicato (prezzoImpostoOk
    // dipende da scontoStato==='approvato') e il riepilogo / lista / PDF mostrano il
    // totale calcolato invece del PI praticato. Vedi bug Matteo 22/05/2026 mattina:
    // offerta salvata con auto-approvazione v=65 ma riepilogo non mostrava sezione PI.
    if ($piAttivo && $margineOk && $statoSc === '') {
        $d['scontoStato'] = 'approvato';
        bp_audit(
            'pi_auto_approved',
            'offerte',
            $d['id'],
            sprintf('PI auto-approvato (margine %.2f%% >= soglia %d%% ruolo %s)', $cValid['mP'], $minMargine, $me['ruolo']),
            $actor
        );
    }

    bp_offerta_upsert($d, $actor);
    if ($om > 0) {
        bp_audit('overmarkup_set', 'offerte', $d['id'], "overmarkup=$om%", $actor);
    }
    bp_json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    bp_require_csrf();
    $id = $_GET['id'] ?? '';
    if (!$id) bp_json_out(['error' => 'id mancante'], 400);

    // Verifica permessi: solo owner o admin (NB: supervisore non può eliminare, solo modificare)
    $stmt = bp_db()->prepare("SELECT user_id FROM offerte WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        bp_json_out(['ok' => true]);  // già cancellata, idempotente
    }
    $isOwner = ($existing['user_id'] ?? '') === $me['id'];
    if ($me['ruolo'] !== 'admin' && !$isOwner) {
        bp_audit('forbidden', 'offerte', $id, 'tentativo eliminazione offerta altrui');
        bp_json_out(['error' => 'Non puoi eliminare offerte di altri utenti'], 403);
    }

    bp_offerta_delete($id, $actor);
    bp_json_out(['ok' => true]);
}

bp_json_out(['error' => 'Metodo non consentito'], 405);
