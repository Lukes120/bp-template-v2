<?php
/**
 * Wrapper PHPMailer con configurazione presa da credentials.env.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function bp_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = bp_env('SMTP_HOST', 'smtp.gmail.com');
    $mail->SMTPAuth   = true;
    $mail->Username   = bp_env('SMTP_USERNAME');
    $mail->Password   = bp_env('SMTP_PASSWORD');
    $mail->SMTPSecure = bp_env('SMTP_SECURE', 'tls') === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)bp_env('SMTP_PORT', '587');
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(bp_env('SMTP_FROM_EMAIL', bp_env('SMTP_USERNAME')), bp_env('SMTP_FROM_NAME', 'BP Template'));
    return $mail;
}

function bp_mail_credenziali(string $email, string $nome, string $username, string $password): void {
    $url = bp_env('APP_URL', '');
    $mail = bp_mailer();
    $mail->addAddress($email, $nome);
    $mail->isHTML(true);
    $mail->Subject = 'Accesso BP Template - Ecotel Italia';
    $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px">
  <h2 style="color:#1e3a8a">BP Template - Ecotel Italia</h2>
  <p>Ciao <b>' . htmlspecialchars($nome) . '</b>,</p>
  <p>Ecco le tue credenziali di accesso al BP Template:</p>
  <div style="background:#f3f4f6;border-radius:8px;padding:16px;margin:16px 0">
    <p><b>Link:</b> <a href="' . htmlspecialchars($url) . '" style="color:#1e3a8a;font-weight:bold">BP Template - Ecotel Italia</a></p>
    <p><b>Username:</b> ' . htmlspecialchars($username) . '</p>
    <p><b>Password temporanea:</b> ' . htmlspecialchars($password) . '</p>
  </div>
  <p style="color:#dc2626"><b>Al primo accesso ti verra chiesto di cambiare la password.</b></p>
  <p style="color:#9ca3af;font-size:12px">Ecotel Italia - BP Template</p>
</div>';
    $mail->send();
}

/**
 * Colore del badge margine % in base alle soglie comuni (20/10/0).
 */
function bp_mail_color_margine(float $mP): string {
    if ($mP >= 20) return '#15803d'; // verde
    if ($mP >= 10) return '#ca8a04'; // giallo
    return '#dc2626';                // rosso
}

/**
 * Mail al supervisore/admin per richiesta approvazione (sconto direzione o prezzo imposto).
 * $tipo: 'sconto' | 'prezzo_imposto'
 */
function bp_mail_richiesta_approvazione(
    array $destinatari, string $tipo, string $offerta, string $cliente, string $nOrdine,
    string $utente, string $valoreFmt, float $mP, string $linkUrl
): void {
    $isPI       = $tipo === 'prezzo_imposto';
    $headerBg   = $isPI ? '#7c3aed' : '#1e3a8a';
    $tipoLabel  = $isPI ? 'PREZZO IMPOSTO' : 'SCONTO DIREZIONE';
    $valLabel   = $isPI ? 'Prezzo Imposto richiesto' : 'Totale offerta (con sconto)';
    $tipoTesto  = $isPI ? 'un <strong>Prezzo Imposto</strong>' : 'uno <strong>Sconto Direzione</strong>';
    $hex        = bp_mail_color_margine($mP);
    $hexBg      = $hex . '15';
    $mPFmt      = number_format($mP, 1, ',', '.');
    $linkSafe   = htmlspecialchars($linkUrl);
    $offertaEsc = htmlspecialchars($offerta);
    $clienteEsc = htmlspecialchars($cliente !== '' ? $cliente : '—');
    $utenteEsc  = htmlspecialchars($utente);
    $nOrdineRow = $nOrdine !== ''
        ? '<div style="font-size:13px;color:#6b7280;margin-top:6px">N. Odoo: <span style="color:#7c3aed;font-weight:700">' . htmlspecialchars($nOrdine) . '</span></div>'
        : '';

    $mail = bp_mailer();
    foreach ($destinatari as $sup) {
        $mail->addAddress($sup['email'], $sup['nome']);
    }
    $mail->isHTML(true);
    $mail->Subject = ($isPI ? 'Richiesta Prezzo Imposto' : 'Richiesta Sconto') . ' — ' . $offerta;
    $mail->Body = '<!doctype html><html lang="it"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)">'
        // Header
        . '<tr><td style="background:' . $headerBg . ';padding:28px 32px;color:#ffffff">'
        . '<div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.85;margin-bottom:8px">BP Template</div>'
        . '<div style="font-size:22px;font-weight:bold;line-height:1.3">Approvazione richiesta</div>'
        . '<div style="font-size:14px;opacity:.85;margin-top:4px">' . $tipoLabel . '</div>'
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:28px 32px">'
        . '<div style="font-size:15px;line-height:1.5;margin:0 0 24px 0"><strong>' . $utenteEsc . '</strong> ha richiesto l\'approvazione di ' . $tipoTesto . ' sulla seguente offerta:</div>'
        // Card commessa
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:16px"><tr><td style="padding:16px 18px">'
        . '<div style="font-size:11px;color:#6b7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">Commessa</div>'
        . '<div style="font-size:16px;font-weight:bold;color:#111827">' . $offertaEsc . '</div>'
        . '<div style="font-size:13px;color:#374151;margin-top:6px">Cliente: ' . $clienteEsc . '</div>'
        . $nOrdineRow
        . '</td></tr></table>'
        // Card dati economici
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:24px"><tr><td style="padding:16px 18px">'
        . '<div style="font-size:11px;color:#6b7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px">Dati economici</div>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">'
        . '<tr><td style="font-size:14px;color:#374151;padding:6px 0">' . $valLabel . '</td>'
        . '<td style="font-size:16px;font-weight:bold;color:#111827;text-align:right;padding:6px 0">EUR ' . htmlspecialchars($valoreFmt) . '</td></tr>'
        . '<tr><td style="font-size:14px;color:#374151;padding:6px 0">Margine risultante</td>'
        . '<td style="text-align:right;padding:6px 0"><span style="display:inline-block;padding:4px 12px;background:' . $hexBg . ';color:' . $hex . ';font-weight:bold;font-size:15px;border-radius:6px">' . $mPFmt . '%</span></td></tr>'
        . '</table></td></tr></table>'
        // CTA button
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr><td align="center" style="padding:8px 0">'
        . '<a href="' . $linkSafe . '" style="display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:8px;font-weight:bold;font-size:14px;letter-spacing:.5px">APRI OFFERTA</a>'
        . '</td></tr></table>'
        . '</td></tr>'
        // Footer
        . '<tr><td style="background:#f3f4f6;padding:16px 32px;text-align:center;font-size:11px;color:#6b7280;border-top:1px solid #e5e7eb;line-height:1.6">'
        . 'Ecotel Italia · BP Template<br>Email automatica · non rispondere a questo messaggio'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
    $mail->send();
}

/**
 * Mail di notifica esito al richiedente quando l'admin/supervisore approva o rifiuta.
 * $tipo: 'sconto' | 'prezzo_imposto'
 * $esito: 'approvato' | 'rifiutato'
 */
function bp_mail_esito_richiesta(
    string $email, string $nome, string $tipo, string $esito,
    string $offerta, string $cliente, string $nOrdine, string $motivo,
    string $approvatoreNome, string $approvatoreTimestamp, string $linkUrl
): void {
    $isPI       = $tipo === 'prezzo_imposto';
    $isApprov   = $esito === 'approvato';
    $cosa       = $isPI ? 'Prezzo Imposto' : 'Sconto Direzione';
    $cosaUp     = $isPI ? 'PREZZO IMPOSTO' : 'SCONTO DIREZIONE';
    $statoTxt   = $isApprov ? 'APPROVATO' : 'RIFIUTATO';
    $headerBg   = $isApprov ? '#15803d' : '#dc2626';
    $icona      = $isApprov ? '&#10003;' : '&#10007;'; // ✓ / ✗
    $linkSafe   = htmlspecialchars($linkUrl);
    $offertaEsc = htmlspecialchars($offerta);
    $clienteEsc = htmlspecialchars($cliente !== '' ? $cliente : '—');
    $nomeEsc    = htmlspecialchars($nome);
    $approv     = htmlspecialchars($approvatoreNome !== '' ? $approvatoreNome : 'un supervisore');
    $when       = htmlspecialchars($approvatoreTimestamp);
    $nOrdineRow = $nOrdine !== ''
        ? '<div style="font-size:13px;color:#6b7280;margin-top:6px">N. Odoo: <span style="color:#7c3aed;font-weight:700">' . htmlspecialchars($nOrdine) . '</span></div>'
        : '';

    // Callout motivo rifiuto (solo se rifiutato + motivo presente)
    $motivoBlk = '';
    if (!$isApprov && $motivo !== '') {
        $motivoBlk = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#fef2f2;border-left:4px solid #dc2626;border-radius:4px;margin-bottom:24px"><tr><td style="padding:14px 18px">'
            . '<div style="font-size:11px;color:#991b1b;letter-spacing:1px;text-transform:uppercase;font-weight:bold;margin-bottom:6px">Motivo del rifiuto</div>'
            . '<div style="font-size:14px;color:#7f1d1d;line-height:1.5">' . nl2br(htmlspecialchars($motivo)) . '</div>'
            . '</td></tr></table>';
    }

    // Next steps
    if ($isApprov) {
        $steps = ['Allega offerta a Odoo (PDF + Excel)', 'Conferma offerta cliente'];
    } else {
        $steps = ['Rivedi i costi nelle 5 sezioni', 'Modifica lo sconto o disattiva il Prezzo Imposto', 'Rinvia la richiesta quando pronto'];
    }
    $stepsHtml = '';
    foreach ($steps as $s) {
        $stepsHtml .= '<li style="padding:4px 0">' . htmlspecialchars($s) . '</li>';
    }
    $nextStepsBlk = '<div style="font-size:13px;color:#6b7280;letter-spacing:1px;text-transform:uppercase;font-weight:bold;margin-bottom:8px">Prossimi passi</div>'
        . '<ul style="font-size:14px;color:#374151;line-height:1.5;margin:0 0 24px 0;padding-left:20px">' . $stepsHtml . '</ul>';

    $mail = bp_mailer();
    $mail->addAddress($email, $nome);
    $mail->isHTML(true);
    $mail->Subject = $cosa . ' ' . ($isApprov ? 'approvato' : 'rifiutato') . ' — ' . $offerta;
    $mail->Body = '<!doctype html><html lang="it"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)">'
        // Header con icona ✓/✗
        . '<tr><td style="background:' . $headerBg . ';padding:28px 32px;color:#ffffff">'
        . '<div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.85;margin-bottom:8px">BP Template</div>'
        . '<div style="font-size:22px;font-weight:bold;line-height:1.3"><span style="display:inline-block;margin-right:10px">' . $icona . '</span>' . $statoTxt . '</div>'
        . '<div style="font-size:14px;opacity:.85;margin-top:4px">' . $cosaUp . '</div>'
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:28px 32px">'
        . '<div style="font-size:15px;line-height:1.5;margin:0 0 16px 0">Ciao <strong>' . $nomeEsc . '</strong>,</div>'
        . '<div style="font-size:15px;line-height:1.5;margin:0 0 24px 0">La tua richiesta di <strong>' . $cosa . '</strong> è stata <strong style="color:' . $headerBg . '">' . $statoTxt . '</strong> da <strong>' . $approv . '</strong> il ' . $when . '.</div>'
        // Card commessa
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:24px"><tr><td style="padding:16px 18px">'
        . '<div style="font-size:11px;color:#6b7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">Commessa</div>'
        . '<div style="font-size:16px;font-weight:bold;color:#111827">' . $offertaEsc . '</div>'
        . '<div style="font-size:13px;color:#374151;margin-top:6px">Cliente: ' . $clienteEsc . '</div>'
        . $nOrdineRow
        . '</td></tr></table>'
        . $motivoBlk
        . $nextStepsBlk
        // CTA button
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr><td align="center" style="padding:8px 0">'
        . '<a href="' . $linkSafe . '" style="display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:8px;font-weight:bold;font-size:14px;letter-spacing:.5px">APRI OFFERTA</a>'
        . '</td></tr></table>'
        . '</td></tr>'
        // Footer
        . '<tr><td style="background:#f3f4f6;padding:16px 32px;text-align:center;font-size:11px;color:#6b7280;border-top:1px solid #e5e7eb;line-height:1.6">'
        . 'Ecotel Italia · BP Template<br>Email automatica · non rispondere a questo messaggio'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
    $mail->send();
}
