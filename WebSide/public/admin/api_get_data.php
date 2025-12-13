<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset("utf8mb4");

if ($db->connect_error) {
    die(json_encode(['error' => 'DB error']));
}

$draw = intval($_POST['draw'] ?? 1);
$start = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 25);
$search = $_POST['search']['value'] ?? '';

$where = "1=1";
if ($search) {
    $s = $db->real_escape_string($search);
    $where = "(`Age of the patient` LIKE '%$s%' OR `Gender of the patient` LIKE '%$s%' OR Result LIKE '%$s%')";
}

$r_total = $db->query("SELECT COUNT(*) as cnt FROM test_data");
$total = $r_total->fetch_assoc()['cnt'];

$r_filtered = $db->query("SELECT COUNT(*) as cnt FROM test_data WHERE $where");
$filtered = $r_filtered->fetch_assoc()['cnt'];

$r_data = $db->query("SELECT * FROM test_data WHERE $where ORDER BY id LIMIT $start, $length");

$data = [];
while ($row = $r_data->fetch_assoc()) {
    $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $total,
    'recordsFiltered' => $filtered,
    'data' => $data
]);

$db->close();
?>
