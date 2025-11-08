<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';

require_login();
if (!is_staff()) {
  header("Location: /index.php");
  exit;
}
$current_page = basename($_SERVER['PHP_SELF']);
?>



<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content">

  <div class="dashboard-row">
    <div class="card">
      <h5>Disease Distribution</h5>
      <div class="chart-container">
        <canvas id="distChart"></canvas>
      </div>
    </div>

    <div class="card">
      <h5>Gender Breakdown</h5>
      <div class="chart-container">
        <canvas id="genderChart"></canvas>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Recent Prediction Activity</h3>
    <table class="data-table display nowrap" style="width:100%">
      <thead>
        <tr>
          <th>Patient ID</th>
          <th>Gender</th>
          <th>Prediction</th>
          <th>Probability</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $pdo = getPDO();
        $sql = "SELECT patient_id, gender, predicted_label AS prediction, probability, created_at FROM predictions ORDER BY created_at DESC LIMIT 10";
        $stmt = $pdo->query($sql);

        if ($stmt === false) {
          $errorInfo = $pdo->errorInfo();
          $errorMsg = ($errorInfo[0] !== '00000')
            ? "SQL Error: " . htmlspecialchars($errorInfo[2])
            : "Database query failed.";
          echo "<tr><td colspan='4' style='text-align:center;'>{$errorMsg}</td></tr>";
        } else {
          $has_rows = false;
          while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $decrypted_id = decrypt_data($row['patient_id']);
            $decrypted_gender = decrypt_data($row['gender']);
            $decrypted_prediction = decrypt_data($row['prediction']);
            $probability = htmlspecialchars($row['probability']);
            $created_at = htmlspecialchars($row['created_at']);

            echo "<tr>
                    <td>{$decrypted_id}</td>
                    <td>{$decrypted_gender}</td>
                    <td>{$decrypted_prediction}</td>
                    <td>{$probability}%</td>
                    <td>{$created_at}</td>
                  </tr>";
            $has_rows = true;
          }
          if (!$has_rows) {
            echo "<tr><td colspan='4' style='text-align:center;'>No prediction records found.</td></tr>";
          }
        }
        ?>
      </tbody>
    </table>
  </div>

</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- jQuery + DataTables -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<!-- Initialize Charts -->
<script>
async function loadCharts() {
  try {
    const res = await fetch('predictions.php?ajax=1');
    if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
    const data = await res.json();

    // Disease Distribution
    new Chart(document.getElementById('distChart'), {
      type: 'pie',
      data: {
        labels: data.labels,
        datasets: [{
          data: data.counts,
          backgroundColor: ['#0047AB', '#007BFF', '#36A2EB', '#80D0FF']
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
      }
    });

    // Gender Breakdown
    new Chart(document.getElementById('genderChart'), {
      type: 'bar',
      data: {
        labels: data.genderLabels,
        datasets: [{
          label: 'Count',
          data: data.genderCounts,
          backgroundColor: '#0047AB'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } },
        plugins: { legend: { display: false } }
      }
    });
  } catch (error) {
    console.error('Error loading charts:', error);
  }
}
loadCharts();
</script>

<!-- Initialize DataTable -->
<script>
$(document).ready(function () {
  $('.data-table').DataTable({
    pageLength: 5,
    lengthMenu: [5, 10, 25, 50],
    ordering: true,
    searching: true,
    responsive: true,
    dom: 'Bfrtip',
    buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
    language: {
      search: "_INPUT_",
      searchPlaceholder: "Search predictions..."
    }
  });
});
</script>

<?php include 'includes/footer.php'; ?>
