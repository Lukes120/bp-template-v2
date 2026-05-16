<?php
/**
 * Backfill della colonna utenti.odoo_uid per gli utenti che si erano loggati
 * prima del deploy del filtro per "Addetto Vendite" (sale.order.user_id).
 *
 * Per ogni utente con odoo_uid NULL (escluso 'u0' admin locale), interroga
 * res.users di Odoo cercando login = username e popola la colonna.
 *
 * Idempotente: si può rilanciare in sicurezza quando vuoi.
 *
 * Esecuzione (sulla VM di produzione, da PowerShell):
 *   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "C:\laragon\www\bp-template-v2\tools\backfill_odoo_uid.php"
 */

require __DIR__ . '/../core/bootstrap.php';
require __DIR__ . '/../core/odoo.php';

$pdo = bp_db();

$rows = $pdo->query(
    "SELECT id, nome, username, ruolo FROM utenti
     WHERE odoo_uid IS NULL AND id <> 'u0'
     ORDER BY username"
)->fetchAll();

if (!$rows) {
    echo "Nessun utente da aggiornare. Tutti hanno gia' odoo_uid valorizzato.\n";
    exit(0);
}

echo "Trovati " . count($rows) . " utenti senza odoo_uid:\n";
foreach ($rows as $r) {
    echo " - " . $r['username'] . " (" . $r['nome'] . ", " . $r['ruolo'] . ")\n";
}
echo str_repeat('-', 60) . "\n";

$cookieFile = '';
if (!bp_odoo_login($cookieFile)) {
    fwrite(STDERR, "ERRORE: login Odoo fallito (controlla credentials.env)\n");
    exit(1);
}

$updateStmt = $pdo->prepare("UPDATE utenti SET odoo_uid = :uid, updated_at = datetime('now') WHERE id = :id");

$ok = 0; $missing = 0; $errors = 0;
foreach ($rows as $r) {
    $username = $r['username'];
    try {
        $res = bp_odoo_call_kw(
            $cookieFile,
            'res.users',
            'search_read',
            [[['login', '=', $username]]],
            ['fields' => ['id', 'login', 'name'], 'limit' => 1]
        );
        $hit = $res['result'][0] ?? null;
        if ($hit && !empty($hit['id'])) {
            $uid = (int)$hit['id'];
            $updateStmt->execute(['uid' => $uid, 'id' => $r['id']]);
            bp_audit('odoo_uid_backfill', 'utenti', $r['id'], "uid=$uid (login=$username)");
            echo sprintf("[OK]      %-40s -> odoo_uid=%d (%s)\n", $username, $uid, $hit['name'] ?? '');
            $ok++;
        } else {
            echo sprintf("[MANCA]   %-40s nessun res.users trovato per login\n", $username);
            $missing++;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[ERR]     %-40s %s\n", $username, $e->getMessage()));
        $errors++;
    }
}

@unlink($cookieFile);

echo str_repeat('-', 60) . "\n";
echo "Completato: $ok aggiornati, $missing senza match Odoo, $errors errori\n";
exit($errors > 0 ? 2 : 0);
