<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/excel.php';

if (!bp_current_user()) { http_response_code(401); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}
$csrfCookie = $_COOKIE['bp_csrf'] ?? '';
$csrfHeader = $_SERVER['HTTP_X_BP_CSRF'] ?? '';
if (!$csrfCookie || !$csrfHeader || !hash_equals($csrfCookie, $csrfHeader)) { http_response_code(403); exit; }

$d = bp_json_input();
if (empty($d['form'])) { http_response_code(400); exit; }

$xlsx = bp_xlsx_offerta($d['form'], $d['utente'] ?? null);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="offerta.xlsx"');
echo $xlsx;
