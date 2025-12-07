<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_login();
if (!is_admin()) {
  header("Location: /index.php");
  exit;
}

function loadCSV($file) {
  if (!file_exists($file)) {
    die("❌ CSV file not found: " . htmlspecialchars($file));
  }
  $rows = array_map('str_getcsv', file($file));
  $header = array_shift($rows);
  $data = [];
  $header_count = count($header);
  foreach ($rows as $r) {
    if (count($r) < $header_count) $r = array_pad($r, $header_count, '');
    $data[] = array_combine($header, $r);
  }
  return [$header, $data];
}

$mode = $_GET["mode"] ?? "test";
if ($mode === "full") {
    [$header, $dataset] = loadCSV(__DIR__ . "/../Liver Patient Dataset (LPD)_train.csv");
} else {
    [$header, $dataset] = loadCSV(__DIR__ . "/../model_test_results.csv");
}


$clean_header = [];
foreach ($header as $h) {
  if ($mode === "test") {
    if (!in_array($h, ["label", "true_labelwise", "Correct?"])) $clean_header[] = $h;
  } else {
    if (!in_array($h, ["label"])) $clean_header[] = $h;
  }
}
$current_page = basename($_SERVER['PHP_SELF']);
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
  font-family: 'Segoe UI', sans-serif;
  background: #f8f9fa;
  margin: 0;
  overflow-x: hidden;
}
.content {
  padding: 25px;
  background: #f8f9fa;
  transition: margin-left 0.3s;
  min-height: calc(100vh - 60px);
}
.container-fluid {
  flex-grow: 1; /* allows content to expand but not hide footer */
  max-width: 95%;
  margin: 0 auto;
}

h3 {
  font-weight: 600;
  color: #0047AB;
}
select#datasetMode {
  border: 1px solid #0047AB;
  border-radius: 8px;
  padding: 6px 10px;
  background: white;
  font-weight: 500;
  transition: 0.3s;
}
select#datasetMode:hover {
  background: #e6f0ff;
}

/* ====== Card Section ====== */
.data-card {
  border-radius: 10px;
  background: white;
  box-shadow: 0 3px 10px rgba(0,0,0,0.08);
  padding: 10px;
}

/* ====== DataTable Styling ====== */
/* .table-area {
  overflow-x: auto;
  overflow-y: auto;
  max-height: 80vh;
  border-radius: 8px;
} */
.card {

  height: auto;

}

#datasetTable {
  width: 100% !important;
  border-collapse: collapse;
}

#datasetTable thead th {
  background-color: #0047AB;
  color: #fff;
  font-weight: 600;
  text-align: center;
  padding: 10px;
}

#datasetTable tbody td {
  padding: 8px 10px;
  text-align: center;
  vertical-align: middle;
}

#datasetTable tbody tr:nth-child(even) {
  background-color: #f3f6fa;
}

#datasetTable tbody tr:hover {
  background-color: #e9f1ff;
}

/* Buttons + Controls */
.dt-buttons .dt-button {
  background-color: #0047AB !important;
  border: none;
  border-radius: 6px !important;
  color: #fff !important;
  padding: 6px 12px !important;
  margin: 5px 3px;
  font-size: 0.9rem;
  font-weight: 500;
  transition: 0.3s;
}
.dt-buttons .dt-button:hover {
  background-color: #00398f !important;
}
.dataTables_wrapper .dataTables_filter input {
  border-radius: 6px;
  padding: 5px 10px;
  border: 1px solid #ccc;
}
.dataTables_length select {
  border-radius: 6px;
  border: 1px solid #ccc;
}

/* Predict Button */
.predict-btn {
  border: 1px solid #0047AB;
  background-color: #fff;
  color: #0047AB;
  font-weight: 500;
  border-radius: 6px;
  transition: 0.3s;
}
.predict-btn:hover {
  background-color: #0047AB;
  color: white;
}

/* ====== ColVis Dropdown Styling ====== */
div.dt-button-collection {
  max-height: 300px !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  border-radius: 8px !important;
  box-shadow: 0 3px 8px rgba(0,0,0,0.2);
}
div.dt-button-collection button.dt-button {
  background: white !important;
  color: #0047AB !important;
  border: none !important;
  text-align: left !important;
  width: 100% !important;
}
div.dt-button-collection button.dt-button.active {
  background-color: #0047AB !important;
  color: white !important;
}

footer {
  position: fixed;
  bottom: 0;
  left: 250px; /* matches your sidebar width */
  width: calc(100% - 250px); /* full width minus sidebar */
  background: #fff;
  border-top: 1px solid #ddd;
  padding: 12px 0;
  text-align: center;
  font-size: 0.9rem;
  color: #555;
  z-index: 100;
  box-shadow: 0 -2px 5px rgba(0,0,0,0.05);
}

</style>

<div class="content">
  <div class="container-fluid">
    <div class="card shadow-sm p-3 data-card">
      <div class="mb-3 d-flex justify-content-between align-items-center">
        <div>
          <select id="datasetMode" onchange="location='dataset.php?mode='+this.value">
            <option value="test" <?= ($mode == "test" ? "selected" : "") ?>>Test Dataset</option>
            <option value="full" <?= ($mode == "full" ? "selected" : "") ?>>Full Dataset</option>
          </select>
        </div>
        <div id="dtButtonsContainer"></div>
      </div>

      <div class="table-area">
        <table id="datasetTable" class="display nowrap">
          <thead>
            <tr>
              <?php foreach ($clean_header as $h): ?>
                <th><?= htmlspecialchars($h) ?></th>
              <?php endforeach; ?>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dataset as $row): ?>
              <tr>
                <?php foreach ($clean_header as $h): ?>
                  <td><?= htmlspecialchars($row[$h] ?? '') ?></td>
                <?php endforeach; ?>
                <td>
                  <button class="predict-btn btn btn-sm" data-row='<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>'>
                    🔍 Predict
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Prediction Modal -->
<div class="modal fade" id="predictModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content p-4">
      <h5 class="mb-3 text-primary fw-bold">Prediction Result</h5>
      <div class="modal-body">
        <p><strong>Prediction:</strong> <span id="modalPrediction"></span></p>
        <p><strong>Risk Level:</strong> <span id="modalRisk"></span></p>
        <p><strong>Confidence:</strong> <span id="modalConfidence"></span></p>
        <hr>
        <p><strong>Explanation Summary:</strong></p>
        <p id="modalExplanationText" class="fst-italic"></p>
        <p><strong>Top Contributing Factors:</strong></p>
        <ul id="modalFactors" class="factor-list"></ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"/>
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css"/>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
  const table = $('#datasetTable').DataTable({
    scrollX: true,
    scrollY: '65vh',
    scrollCollapse: true,
    paging: true,
    pageLength: 7,
    dom: 'Bfrtip',
    buttons: [
      {
        extend: 'colvis',
        text: '👁️ Show / Hide Columns',
        columns: ':not(:last-child)',
        postfixButtons: ['colvisRestore']
      },
      'copy', 'csv', 'excel', 'pdf', 'print'
    ],
    language: {
      search: "_INPUT_",
      searchPlaceholder: "Search dataset..."
    }
  });
  $('#dtButtonsContainer').append(table.buttons().container());

  const predictModal = new bootstrap.Modal(document.getElementById('predictModal'));

  $('#datasetTable tbody').on('click', '.predict-btn', function() {
    const rowData = JSON.parse($(this).attr('data-row'));

    fetch('predict_row.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(rowData)
    })
    .then(res => res.json())
    .then(resp => {
      if (resp.success) {
        $('#modalPrediction').text(resp.prediction ?? 'N/A');
        $('#modalRisk').text(resp.risk_level ?? 'N/A');
        $('#modalConfidence').text(((resp.probability ?? 0) * 100).toFixed(2) + '%');
        $('#modalExplanationText').text(resp.explanation_text ?? 'No detailed explanation available.');

        const list = $('#modalFactors');
        list.empty();
        if (Array.isArray(resp.top_factors) && resp.top_factors.length) {
          resp.top_factors.forEach(f => {
            list.append(`<li>${f.explanation}</li>`);
          });
        } else {
          list.append('<li>No factor insights available.</li>');
        }

        predictModal.show();
      } else {
        alert('Error: ' + (resp.error || 'Unknown'));
      }
    })
    .catch(err => { console.error(err); alert('Request failed'); });
  });
});
</script>

<?php include 'includes/footer.php'; ?>
