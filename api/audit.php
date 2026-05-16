<?php
require_once __DIR__ . '/../core/bootstrap.php';
bp_cors_json();
bp_require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    bp_json_out(['error' => 'Metodo non consentito'], 405);
}

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1) $limit = 100;
if ($limit > 500) $limit = 500;

$where = []; $params = [];
if (!empty($_GET['user_id'])) { $where[] = 'user_id = :uid'; $params['uid'] = $_GET['user_id']; }
if (!empty($_GET['action']))  { $where[] = 'action = :act';  $params['act'] = $_GET['action']; }
if (!empty($_GET['entity']))  { $where[] = 'entity = :ent';  $params['ent'] = $_GET['entity']; }

$sql = "SELECT id, ts, user_id, user_name, action, entity, entity_id, detail FROM audit_log";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY id DESC LIMIT " . $limit;

$stmt = bp_db()->prepare($sql);
$stmt->execute($params);
bp_json_out($stmt->fetchAll());
