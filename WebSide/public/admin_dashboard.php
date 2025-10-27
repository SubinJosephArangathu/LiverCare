<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
if(!is_admin()){ header("Location: /index.php"); exit; }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <div class="sidebar">
    <a href="/admin_dashboard.php">Dashboard</a>
    <a href="/admin_predictions.php">Predictions</a>
    <a href="/admin_dataset.php">Dataset & Validation</a>
    <a href="/admin_users.php">Users</a>
    <a href="/logout.php">Logout</a>
  </div>

  <div class="content">
    <h2>Admin Dashboard</h2>

    <div class="card">
      <h3>Disease distribution</h3>
      <canvas id="distChart" width="400" height="200"></canvas>
    </div>

    <!-- <div class="card">
      <h3>Gender breakdown</h3>
      <canvas id="genderChart" width="400" height="200"></canvas>
    </div> -->

  </div>

<script>
async function loadCharts(){
  const res = await fetch('/admin_predictions.php?ajax=1');
  const data = await res.json();

  // data.example: { labels: [...], counts: [...], genderLabels: [...], genderCounts: [...] }
  const ctx1 = document.getElementById('distChart').getContext('2d');
  new Chart(ctx1, {
    type: 'pie',
    data: { labels: data.labels, datasets:[{ data: data.counts }] }
  });

  const ctx2 = document.getElementById('genderChart').getContext('2d');
  new Chart(ctx2, {
    type: 'bar',
    data: { labels: data.genderLabels, datasets:[{ label:'Count', data: data.genderCounts }] }
  });
}

loadCharts();
</script>
</body>
</html>
