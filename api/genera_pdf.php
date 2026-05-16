<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pdf.php';

if (!bp_current_user()) { http_response_code(401); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}
$csrfCookie = $_COOKIE['bp_csrf'] ?? '';
$csrfHeader = $_SERVER['HTTP_X_BP_CSRF'] ?? '';
if (!$csrfCookie || !$csrfHeader || !hash_equals($csrfCookie, $csrfHeader)) { http_response_code(403); exit; }

$d = bp_json_input();
if (empty($d['form'])) { http_response_code(400); exit; }

$pdf = bp_pdf_offerta($d['form'], $d['utente'] ?? '');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="offerta.pdf"');
echo $pdf;
