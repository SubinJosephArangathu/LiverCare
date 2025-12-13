<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset("utf8mb4");

if ($db->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'DB error: ' . $db->connect_error]));
}

try {
    // Total count
    $r = $db->query("SELECT COUNT(*) as cnt FROM test_data");
    if (!$r) throw new Exception("Count query failed");
    $total = intval($r->fetch_assoc()['cnt']);

    // Disease count
    $r = $db->query("SELECT COUNT(*) as cnt FROM test_data WHERE Result = 1");
    if (!$r) throw new Exception("Disease count query failed");
    $disease = intval($r->fetch_assoc()['cnt']);

    // Mean age
    $r = $db->query("SELECT AVG(CAST(`Age of the patient` AS DECIMAL(10,2))) as avg FROM test_data");
    if (!$r) throw new Exception("Mean age query failed");
    $row = $r->fetch_assoc();
    $mean_age = floatval($row['avg'] ?? 0);

    // Age distribution (5-year bins)
    $age_bins = [];
    $age_counts = [];
    
    for ($i = 10; $i <= 85; $i += 5) {
        $age_bins[] = "$i-" . ($i + 5);
        $age_counts[] = 0;
    }

    $r = $db->query("
        SELECT 
            FLOOR(CAST(`Age of the patient` AS DECIMAL(10,2)) / 5) * 5 as bin, 
            COUNT(*) as cnt 
        FROM test_data 
        WHERE `Age of the patient` IS NOT NULL
        GROUP BY bin 
        ORDER BY bin
    ");
    
    if (!$r) throw new Exception("Age bins query failed");
    
    while ($row = $r->fetch_assoc()) {
        $bin_val = intval($row['bin']);
        $idx = intval(($bin_val - 10) / 5);
        if (isset($age_counts[$idx])) {
            $age_counts[$idx] = intval($row['cnt']);
        }
    }

    // TB statistics
    $r = $db->query("
        SELECT 
            MIN(CAST(`Total Bilirubin` AS DECIMAL(10,2))) as tb_min,
            MAX(CAST(`Total Bilirubin` AS DECIMAL(10,2))) as tb_max
        FROM test_data 
        WHERE `Total Bilirubin` IS NOT NULL AND `Total Bilirubin` != ''
    ");
    
    if (!$r) throw new Exception("TB min/max query failed");
    $tb_stats = $r->fetch_assoc();
    $tb_min = floatval($tb_stats['tb_min'] ?? 0);
    $tb_max = floatval($tb_stats['tb_max'] ?? 0);

    // Get all TB values for quartiles
    $r = $db->query("
        SELECT CAST(`Total Bilirubin` AS DECIMAL(10,2)) as tb 
        FROM test_data 
        WHERE `Total Bilirubin` IS NOT NULL AND `Total Bilirubin` != ''
        ORDER BY CAST(`Total Bilirubin` AS DECIMAL(10,2))
    ");
    
    if (!$r) throw new Exception("TB values query failed");
    
    $tb_vals = [];
    while ($row = $r->fetch_assoc()) {
        $tb_vals[] = floatval($row['tb']);
    }

    // Calculate quartiles
    function getQuantile($arr, $p) {
        if (empty($arr)) return 0;
        $count = count($arr);
        $pos = ($count - 1) * $p;
        $base = floor($pos);
        $rest = $pos - $base;
        
        if ($base + 1 < $count) {
            return $arr[$base] + $rest * ($arr[$base + 1] - $arr[$base]);
        }
        return $arr[$base];
    }

    $tb_q1 = floatval(getQuantile($tb_vals, 0.25));
    $tb_median = floatval(getQuantile($tb_vals, 0.50));
    $tb_q3 = floatval(getQuantile($tb_vals, 0.75));

    // Build response
    $response = [
        'success' => true,
        'total' => $total,
        'disease' => $disease,
        'rate' => $total > 0 ? ($disease / $total) * 100 : 0,
        'mean_age' => $mean_age,
        'age_bins' => $age_bins,
        'age_counts' => $age_counts,
        'tb_min' => $tb_min,
        'tb_max' => $tb_max,
        'tb_q1' => $tb_q1,
        'tb_median' => $tb_median,
        'tb_q3' => $tb_q3
    ];

    echo json_encode($response, JSON_NUMERIC_CHECK);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$db->close();
?>
