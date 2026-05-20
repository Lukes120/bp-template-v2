<?php
/**
 * Reset password admin (CLI o localhost HTTP).
 *
 * USO:
 *   php tools/reset_admin_password.php                 -> genera password random
 *   php tools/reset_admin_password.php "MiaPass!2026"  -> imposta una password scelta
 *
 * Funziona SOLO da CLI o localhost (REMOTE_ADDR 127.0.0.1). Token NON richiesto:
 * l'accesso a CLI/localhost e' gia' privilegio sufficiente. Aggiorna 'first_login=1'
 * cosi' al prossimo login admin sara' costretto a cambiarla.
 */
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../core/bootstrap.php';

$isCli   = PHP_SAPI === 'cli';
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($ip, ['127.0.0.1', '::1'], true);

if (!$isCli && !$isLocal) {
    http_response_code(403);
    die("Accessibile solo da CLI o localhost (sulla VM stessa).\n");
}

if (!$isCli) header('Content-Type: text/plain');

$plain = $isCli ? ($argv[1] ?? null) : ($_GET['pw'] ?? null);
if (!$plain) {
    // Genera password random sicura: 14 char alfanumerici + qualche simbolo, leggibile
    $alpha = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $plain = '';
    for ($i = 0; $i < 14; $i++) {
        $plain .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
}
if (strlen($plain) < 8) {
    die("Password troppo corta (min 8 caratteri).\n");
}

$hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = bp_db()->prepare("UPDATE utenti SET password_hash = :h, first_login = 1, updated_at = datetime('now') WHERE username = 'admin'");
$stmt->execute(['h' => $hash]);

if ($stmt->rowCount() === 0) {
    die("ERRORE: utente 'admin' non trovato nel DB.\n");
}

bp_audit('password_reset', 'utenti', 'u0', "reset via reset_admin_password.php (" . ($isCli ? 'CLI' : "localhost $ip") . ")");

echo "==============================================\n";
echo " Password admin resettata con successo\n";
echo "==============================================\n";
echo " Username : admin\n";
echo " Password : $plain\n";
echo "==============================================\n";
echo " Salva la password ORA — non sara' mostrata di nuovo.\n";
echo " Al prossimo login dovrai cambiarla (first_login=1).\n";
