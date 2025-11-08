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
    // Fetch all predictions, decrypt labels and genders, compute counts
    $rows = $pdo->query("SELECT predicted_label, gender FROM predictions")->fetchAll();
    $labelCounts = [];
    $genderCounts = ['Male'=>0,'Female'=>0,'Unknown'=>0];

    foreach($rows as $r){
        // Decrypt predicted label
        $label = decrypt_data($r['predicted_label']);

        // Decrypt gender
        $genderVal = decrypt_data($r['gender']);
        if (!in_array($genderVal, ['Male','Female','Unknown'])) {
            $genderVal = 'Unknown';
        }

        // Count labels
        $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;

        // Count genders
        $genderCounts[$genderVal]++;
    }

    echo json_encode([
        'labels'=>array_keys($labelCounts),
        'counts'=>array_values($labelCounts),
        'genderLabels'=>array_keys($genderCounts),
        'genderCounts'=>array_values($genderCounts)
    ]);
    exit;
}

// Non-AJAX render - show table of predictions
$rows = $pdo->query("SELECT * FROM predictions ORDER BY created_at DESC LIMIT 200")->fetchAll();
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
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Patient</th>
          <th>Gender</th>
          <th>Label</th>
          <th>Probability</th>
          <th>User</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars($r['id'])?></td>
            <td><?=htmlspecialchars(decrypt_data($r['patient_id']))?></td>
            <td>
                <?php
                $genderVal = decrypt_data($r['gender']);
                if (!in_array($genderVal, ['Male','Female','Unknown'])) {
                    $genderVal = 'Unknown';
                }
                echo htmlspecialchars($genderVal);
                ?>
            </td>
            <td><?=htmlspecialchars(decrypt_data($r['predicted_label']))?></td>
            <td><?=htmlspecialchars($r['probability'])?></td>
            <td><?=htmlspecialchars($r['user_id'])?></td>
            <td><?=htmlspecialchars($r['created_at'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
