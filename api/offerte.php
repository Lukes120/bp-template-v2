<?php
require_once __DIR__ . '/../core/bootstrap.php';
bp_cors_json();

$method = $_SERVER['REQUEST_METHOD'];
$me     = bp_require_auth();
$actor  = $me;

// Helper: utenti con ruolo 'admin' o 'supervisore' vedono tutto.
// Gli 'user' possono leggere/scrivere solo le proprie offerte.
$canSeeAll = in_array($me['ruolo'], ['admin', 'supervisore'], true);

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

    // Verifica permessi su offerta esistente: solo owner o admin/supervisore
    $stmt = bp_db()->prepare("SELECT user_id FROM offerte WHERE id = :id");
    $stmt->execute(['id' => $d['id']]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (!$canSeeAll && ($existing['user_id'] ?? '') !== $me['id']) {
            bp_audit('forbidden', 'offerte', $d['id'], 'tentativo modifica offerta altrui');
            bp_json_out(['error' => 'Non puoi modificare offerte di altri utenti'], 403);
        }
    } else {
        // Nuova offerta: l'utente non admin/sup non può falsificare userId
        if (!$canSeeAll) {
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

    bp_offerta_upsert($d, $actor);
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
