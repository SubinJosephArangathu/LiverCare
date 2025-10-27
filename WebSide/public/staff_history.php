<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_login();
if(!is_staff()) { header("Location: /index.php"); exit; }

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT * FROM predictions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>My History</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div class="sidebar">
    <a href="/staff_predict.php">Predict</a>
    <a href="/staff_history.php">My History</a>
    <a href="/logout.php">Logout</a>
  </div>

  <div class="content">
    <h2>My Predictions</h2>
    <table>
      <thead><tr><th>Patient</th><th>Prediction</th><th>Prob</th><th>Created</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars(decrypt_data($r['patient_id']))?></td>
            <td><?=htmlspecialchars(decrypt_data($r['predicted_label']))?></td>
            <td><?=htmlspecialchars($r['probability'])?></td>
            <td><?=htmlspecialchars($r['created_at'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
