<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

// Increase limits
ini_set('memory_limit', '512M');
set_time_limit(300); // 5 minutes

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    die(json_encode(['success' => false, 'error' => 'DB error']));
}
$db->set_charset("utf8mb4");

if (!isset($_FILES['csvFile'])) {
    die(json_encode(['success' => false, 'error' => 'No file']));
}

$file = $_FILES['csvFile'];
if ($file['error'] !== UPLOAD_ERR_OK || !str_ends_with(strtolower($file['name']), '.csv')) {
    die(json_encode(['success' => false, 'error' => 'Invalid file']));
}

// Clear table
$db->query("TRUNCATE TABLE test_data");

$imported = 0;
$failed = 0;
$batch_size = 1000;
$values_arr = [];

if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
    // Skip header
    fgetcsv($handle);

    while (($data = fgetcsv($handle)) !== FALSE) {
        // Prepare values
        $age = !empty($data[0]) ? floatval($data[0]) : 'NULL';
        $gender = !empty($data[1]) ? "'" . $db->real_escape_string($data[1]) . "'" : 'NULL';
        $tb = !empty($data[2]) ? floatval($data[2]) : 'NULL';
        $db_val = !empty($data[3]) ? floatval($data[3]) : 'NULL';
        $alkphos = !empty($data[4]) ? intval($data[4]) : 'NULL';
        $sgpt = !empty($data[5]) ? intval($data[5]) : 'NULL';
        $sgot = !empty($data[6]) ? intval($data[6]) : 'NULL';
        $tp = !empty($data[7]) ? floatval($data[7]) : 'NULL';
        $alb = !empty($data[8]) ? floatval($data[8]) : 'NULL';
        $ag_ratio = !empty($data[9]) ? floatval($data[9]) : 'NULL';
        $result = !empty($data[10]) ? intval($data[10]) : 'NULL';

        $values_arr[] = "($age, $gender, $tb, $db_val, $alkphos, $sgpt, $sgot, $tp, $alb, $ag_ratio, $result)";

        // Execute batch
        if (count($values_arr) >= $batch_size) {
            $sql = "INSERT INTO test_data 
                (`Age of the patient`, `Gender of the patient`, `Total Bilirubin`, `Direct Bilirubin`, 
                 `Alkphos Alkaline Phosphotase`, `Sgpt Alamine Aminotransferase`, `Sgot Aspartate Aminotransferase`, 
                 `Total Protiens`, `ALB Albumin`, `A/G Ratio Albumin and Globulin Ratio`, `Result`)
                VALUES " . implode(', ', $values_arr);

            if ($db->query($sql)) {
                $imported += count($values_arr);
            } else {
                $failed += count($values_arr);
            }

            $values_arr = [];
        }
    }

    // Insert remaining rows
    if (count($values_arr) > 0) {
        $sql = "INSERT INTO test_data 
            (`Age of the patient`, `Gender of the patient`, `Total Bilirubin`, `Direct Bilirubin`, 
             `Alkphos Alkaline Phosphotase`, `Sgpt Alamine Aminotransferase`, `Sgot Aspartate Aminotransferase`, 
             `Total Protiens`, `ALB Albumin`, `A/G Ratio Albumin and Globulin Ratio`, `Result`)
            VALUES " . implode(', ', $values_arr);

        if ($db->query($sql)) {
            $imported += count($values_arr);
        } else {
            $failed += count($values_arr);
        }
    }

    fclose($handle);
}

@unlink($file['tmp_name']);
$db->close();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'imported' => $imported,
    'failed' => $failed
]);
?>
