<?php
/**
 * Migrazione one-shot: importa utenti.json + offerte.json di bp-template legacy nel DB SQLite.
 *
 * Uso:
 *   php migrations/migrate_json_to_sqlite.php /path/to/bp-template/api/data
 *
 * Idempotente: se l'id esiste già nel DB viene aggiornato (upsert).
 * Le password legacy erano in chiaro: vengono rihashate con password_hash().
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';

$srcDir = $argv[1] ?? null;
if (!$srcDir) {
    fwrite(STDERR, "Uso: php migrations/migrate_json_to_sqlite.php <dir-con-utenti.json-e-offerte.json>\n");
    exit(1);
}

$utentiFile  = rtrim($srcDir, '/\\') . DIRECTORY_SEPARATOR . 'utenti.json';
$offerteFile = rtrim($srcDir, '/\\') . DIRECTORY_SEPARATOR . 'offerte.json';

if (!is_readable($utentiFile))  { fwrite(STDERR, "Manca: $utentiFile\n");  exit(1); }
if (!is_readable($offerteFile)) { fwrite(STDERR, "Manca: $offerteFile\n"); exit(1); }

$pdo = bp_db();

/* ---- UTENTI ---- */
$utenti = json_decode(file_get_contents($utentiFile), true) ?: [];
$nUt = 0;
foreach ($utenti as $u) {
    if (empty($u['id']) || empty($u['username'])) continue;
    $plain = $u['password'] ?? null;
    bp_utente_upsert([
        'id'         => $u['id'],
        'nome'       => $u['nome']        ?? $u['username'],
        'username'   => $u['username'],
        'ruolo'      => $u['ruolo']       ?? 'user',
        'email'      => $u['email']       ?? null,
        'firstLogin' => isset($u['firstLogin']) ? (int)$u['firstLogin'] : 0,
    ], $plain);
    $nUt++;
}

/* ---- OFFERTE ---- */
$offerte = json_decode(file_get_contents($offerteFile), true) ?: [];
$nOf = 0;
foreach ($offerte as $o) {
    if (empty($o['id']) || empty($o['nome'])) continue;
    bp_offerta_upsert($o);
    $nOf++;
}

echo "Migrazione completata.\n";
echo "  Utenti:  $nUt\n";
echo "  Offerte: $nOf\n";
echo "  DB:      " . realpath(__DIR__ . '/../data/bp_template.db') . "\n";
echo "\nNB: le password sono state rihashate. Gli utenti possono fare login con le password originali.\n";
