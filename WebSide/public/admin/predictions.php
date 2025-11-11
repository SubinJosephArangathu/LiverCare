<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';

require_login();
if(!is_admin()){ 
    header("Location: /index.php"); 
    exit; 
}

$pdo = getPDO();

if(!empty($_GET['ajax'])){
    // Fetch all predictions
    $rows = $pdo->query("SELECT * FROM predictions ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

    $labelCounts = [];
    $genderCounts = ['Male'=>0,'Female'=>0,'Unknown'=>0];
    $riskCounts = ['Low'=>0,'Medium'=>0,'High'=>0];
    $topFactors = [];
    $patients = [];

    foreach($rows as $r){
        $patientId = decrypt_data($r['patient_id']);
        $genderVal = decrypt_data($r['gender'] ?? '');
        $prediction = decrypt_data($r['predicted_label'] ?? '');
        $probability = floatval($r['probability']);
        $riskLevel = $r['risk_level'] ?? 'Unknown';
        $createdAt = $r['created_at'];
        $age = decrypt_data($r['age'] ?? '');

        if (!in_array($genderVal, ['Male','Female','Unknown'])) $genderVal = 'Unknown';

        $labelCounts[$prediction] = ($labelCounts[$prediction] ?? 0) + 1;
        $genderCounts[$genderVal]++;
        if(isset($riskCounts[$riskLevel])) $riskCounts[$riskLevel]++;

        if(!empty($r['top_factors'])){
            $factorsArr = json_decode($r['top_factors'], true);
            if(is_array($factorsArr)){
                foreach($factorsArr as $factor){
                    $feat = $factor['feature'] ?? 'Unknown';
                    $topFactors[$feat] = ($topFactors[$feat] ?? 0) + 1;
                }
            }
        }

        $patients[] = [
            'id' => $patientId,
            'age' => $age,
            'gender' => $genderVal,
            'prediction' => $prediction,
            'probability' => $probability,
            'risk_level' => $riskLevel,
            'created_at' => $createdAt
        ];
    }

    arsort($topFactors);
    $topFactors = array_slice($topFactors, 0, 10);

    echo json_encode([
        'labels' => array_keys($labelCounts),
        'counts' => array_values($labelCounts),
        'genderLabels' => array_keys($genderCounts),
        'genderCounts' => array_values($genderCounts),
        'riskCounts' => $riskCounts,
        'topFactors' => $topFactors,
        'patients' => $patients
    ]);
    exit;
}

// Non-AJAX fallback table
$rows = $pdo->query("SELECT * FROM predictions ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin - Predictions</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div class="sidebar">
    <a href="/admin_dashboard.php">Dashboard</a>
    <a href="/admin_predictions.php">Predictions</a>
    <a href="/admin_users.php">Users</a>
    <a href="/logout.php">Logout</a>
  </div>
  <div class="content">
    <h2>Recent Predictions</h2>
    <div class="data-table-container">
      <table class="data-table display nowrap" style="width:100%">
        <thead>
          <tr>
            <th>ID</th>
            <th>Patient</th>
            <th>Age</th>
            <th>Gender</th>
            <th>Label</th>
            <th>Probability</th>
            <th>Risk Level</th>
            <th>User</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars($r['id'])?></td>
            <td><?=htmlspecialchars(decrypt_data($r['patient_id']))?></td>
            <td><?=htmlspecialchars(decrypt_data($r['age'] ?? ''))?></td>
            <td><?=htmlspecialchars(decrypt_data($r['gender'] ?? ''))?></td>
            <td><?=htmlspecialchars(decrypt_data($r['predicted_label']))?></td>
            <td><?=htmlspecialchars($r['probability'])?></td>
            <td><?=htmlspecialchars($r['risk_level'] ?? '')?></td>
            <td><?=htmlspecialchars($r['user_id'])?></td>
            <td><?=htmlspecialchars($r['created_at'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function () {
    $('.data-table').DataTable({
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        ordering: true,
        searching: true,
        responsive: true,
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
        language: { search: "_INPUT_", searchPlaceholder: "Search predictions..." }
    });
});
</script>
</body>
</html>
