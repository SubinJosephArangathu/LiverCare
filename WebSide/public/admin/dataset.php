<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

if (!is_admin()) {
    header("Location: /index.php");
    exit;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"/>
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css"/>

<style>
    body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
    .content { padding: 20px; min-height: calc(100vh - 60px); }

    .summary-card { 
        background: white; 
        border-radius: 10px; 
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-left: 4px solid #0047AB;
        text-align: center;
    }
    .summary-label { font-size: 0.8rem; color: #666; text-transform: uppercase; font-weight: 600; }
    .summary-value { font-size: 2rem; font-weight: 700; color: #0047AB; margin-top: 8px; }

    .chart-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }

    .table-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    #datasetTable thead th { background: #0047AB; color: white; padding: 12px; font-size: 0.9rem; }
    #datasetTable tbody td { padding: 10px; font-size: 0.85rem; }

    .predict-btn { background: #0047AB; color: white; border: none; padding: 5px 15px; border-radius: 4px; cursor: pointer; }
    .predict-btn:hover { background: #003580; }

    .badge-disease { background: #dc3545; }
    .badge-healthy { background: #28a745; }
</style>

<div class="content">
    <div class="container-fluid">

        <!-- Header -->
        <div class="mb-4">
            <h2 class="text-primary fw-bold">📊 Liver Disease Dashboard</h2>
            <p class="text-secondary">Dataset Analysis & Test Predictions</p>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="summary-card">
                    <div class="summary-label">Total Records</div>
                    <div class="summary-value" id="totalRecords">-</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="summary-card">
                    <div class="summary-label">Liver Disease Cases</div>
                    <div class="summary-value" id="diseaseCount">-</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="summary-card">
                    <div class="summary-label">Disease Rate</div>
                    <div class="summary-value" id="diseaseRate">-</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="summary-card">
                    <div class="summary-label">Mean Age</div>
                    <div class="summary-value" id="meanAge">-</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row mb-4">
            <div class="col-lg-4 mb-3">
                <div class="chart-card">
                    <h6 class="fw-bold text-primary mb-3">Target Distribution</h6>
                    <canvas id="chartTarget"></canvas>
                </div>
            </div>
            <div class="col-lg-4 mb-3">
                <div class="chart-card">
                    <h6 class="fw-bold text-primary mb-3">Age Distribution</h6>
                    <canvas id="chartAge"></canvas>
                </div>
            </div>
            <div class="col-lg-4 mb-3">
                <div class="chart-card">
                    <h6 class="fw-bold text-primary mb-3">Total Bilirubin</h6>
                    <canvas id="chartTB"></canvas>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="table-card">
            <h6 class="fw-bold text-primary mb-3">📋Dataset</h6>
            <table id="datasetTable" class="display nowrap w-100">
                <thead>
                    <tr>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>TB</th>
                        <th>DB</th>
                        <th>Alkphos</th>
                        <th>Sgpt</th>
                        <th>Sgot</th>
                        <th>TP</th>
                        <th>ALB</th>
                        <th>A/G</th>
                        <th>Result</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

    </div>
</div>

<!-- Prediction Modal -->
<div class="modal fade" id="predictModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Prediction Result</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="predictModalBody"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
$(document).ready(function() {
    const predictModal = new bootstrap.Modal(document.getElementById('predictModal'));

    // ===== LOAD STATS =====
$.ajax({
    url: 'api_get_stats.php',
    type: 'GET',
    dataType: 'json',
    success: (data) => {
        console.log('Stats loaded:', data);
        
        if (!data.success) {
            console.error('API error:', data.error);
            $('#totalRecords').text('Error: ' + data.error);
            return;
        }
        
        // Fill summary cards
        $('#totalRecords').text(data.total.toLocaleString());
        $('#diseaseCount').text(data.disease.toLocaleString());
        $('#diseaseRate').text(data.rate.toFixed(1) + '%');
        $('#meanAge').text(data.mean_age.toFixed(1));

        console.log('Age bins:', data.age_bins);
        console.log('Age counts:', data.age_counts);
        console.log('TB stats:', {q1: data.tb_q1, median: data.tb_median, q3: data.tb_q3});

        // Chart 1: Target Distribution
        const ctx1 = document.getElementById('chartTarget');
        if (ctx1) {
            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: ['Healthy (2)', 'Diseased (1)'],
                    datasets: [{
                        label: 'Count',
                        data: [data.total - data.disease, data.disease],
                        backgroundColor: ['#28a745', '#dc3545']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
            console.log('Chart 1 created');
        }

        // Chart 2: Age Distribution
        const ctx2 = document.getElementById('chartAge');
        if (ctx2) {
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: data.age_bins,
                    datasets: [{
                        label: 'Count',
                        data: data.age_counts,
                        backgroundColor: '#2196f3'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
            console.log('Chart 2 created');
        }

        // Chart 3: TB Distribution
        const ctx3 = document.getElementById('chartTB');
        if (ctx3) {
            new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: ['Min', 'Q1', 'Median', 'Q3', 'Max'],
                    datasets: [{
                        label: 'Total Bilirubin Value',
                        data: [
                            data.tb_min, 
                            data.tb_q1, 
                            data.tb_median, 
                            data.tb_q3, 
                            data.tb_max
                        ],
                        backgroundColor: '#ff9800'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
            console.log('Chart 3 created');
        }
    },
    error: (xhr, status, error) => {
        console.error('AJAX error:', status, error);
        console.error('Response:', xhr.responseText);
        $('#totalRecords').text('Error loading stats');
        alert('Failed to load stats. Check browser console (F12).');
    }
});

    // ===== DATATABLE =====
    const table = $('#datasetTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: 'api_get_data.php',
            type: 'POST'
        },
        columns: [
            { data: 'Age of the patient' },
            { data: 'Gender of the patient' },
            { data: 'Total Bilirubin' },
            { data: 'Direct Bilirubin' },
            { data: 'Alkphos Alkaline Phosphotase' },
            { data: 'Sgpt Alamine Aminotransferase' },
            { data: 'Sgot Aspartate Aminotransferase' },
            { data: 'Total Protiens' },
            { data: 'ALB Albumin' },
            { data: 'A/G Ratio Albumin and Globulin Ratio' },
            {
                data: 'Result',
                render: (d) => d == 1 ? '<span class="badge badge-disease">Disease</span>' : '<span class="badge badge-healthy">Healthy</span>'
            },
            {
                data: null,
                orderable: false,
                render: (d) => '<button class="predict-btn" data-row="' + btoa(JSON.stringify(d)) + '">🔍 Predict</button>'
            }
        ],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100]
    });

        // ===== PREDICT =====
    // ===== PREDICT (Row-based, same style as form page) =====
$(document).on('click', '.predict-btn', function() {
    const rowData = JSON.parse(atob($(this).data('row')));

    const payload = {
        patient_id: rowData.id || ('DS_' + Date.now()),
        Age: parseFloat(rowData["Age of the patient"]),
        Gender: rowData["Gender of the patient"],
        TB: parseFloat(rowData["Total Bilirubin"]),
        DB: parseFloat(rowData["Direct Bilirubin"]),
        Alkphos: parseFloat(rowData["Alkphos Alkaline Phosphotase"]),
        Sgpt: parseFloat(rowData["Sgpt Alamine Aminotransferase"]),
        Sgot: parseFloat(rowData["Sgot Aspartate Aminotransferase"]),
        TP: parseFloat(rowData["Total Protiens"]),
        ALB: parseFloat(rowData["ALB Albumin"]),
        A_G: parseFloat(rowData["A/G Ratio Albumin and Globulin Ratio"])
    };

    fetch('predict_row.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(json => {
        console.log('Row prediction response:', json);

        if (!json.success) {
            alert(json.error || "Prediction failed");
            return;
        }

        // --- Read probability with same fallback logic ---
        const probPrimary = (json.probability_primary ?? json.probability ?? json.disease_probability ?? json.confidence_original ?? 0);
        const probability_percent = (probPrimary * 100).toFixed(2);

        // Normalize prediction label (handle "0"/"1" legacy)
        let predictionLabel = json.prediction;
        if (predictionLabel === "0" || predictionLabel === 0) predictionLabel = "No Liver Disease";
        if (predictionLabel === "1" || predictionLabel === 1) predictionLabel = "Liver Disease";

        const risk = json.risk_level || "Unknown";
        const explanation = json.explanation_text || "";
        const topFactors = json.top_factors || [];
        const second = json.second_opinion || null;
        const food = json.food_recommendations || null;
        const medical_warning = json.medical_warning || null;

        // Same riskMeaning helper
        function riskMeaning(r) {
            if (!r) return "";
            r = String(r).toLowerCase();
            if (r === "low") return "Low: model is confident and indicates low immediate concern.";
            if (r === "medium") return "Medium: some indicators require monitoring or follow-up.";
            if (r === "borderline") return "Borderline: uncertain — monitor and consider further tests.";
            if (r === "mild") return "Mild: early signs of liver abnormality.";
            if (r === "moderate") return "Moderate: clear signs of liver issues; clinical evaluation advised.";
            if (r === "high") return "High: strong indicators of liver disease — urgent clinical follow-up recommended.";
            return "";
        }

        // Build HTML EXACTLY like your predict_action.php page
        let html = `
            <div style="background:#eef;border-left:6px solid #0047AB;padding:12px;border-radius:6px;margin-bottom:15px;">
                <h5><b>Prediction Result</b></h5>
                <p><b>Prediction:</b> ${predictionLabel}</p>
                <p><b>Risk Level:</b> ${risk} <span style="color:#666"> - ${riskMeaning(risk)}</span></p>
                <p><b>Confidence:</b> ${probability_percent}%</p>
            </div>
        `;

        html += `
            <div style="background:#f9f9f9;padding:12px;border-radius:6px;margin-bottom:15px;">
                <h6><b>Model Explanation Summary</b></h6>
                <p>${explanation}</p>
            </div>
        `;

        if (topFactors.length > 0) {
            html += `
                <div style="background:#fff3cd;padding:12px;border-radius:6px;margin-bottom:15px;">
                    <h6><b>Top Contributing Factors (SHAP)</b></h6>
                    <ul>
            `;
            topFactors.forEach(f => {
                html += `
                    <li>
                        <b>${f.feature}</b> — Impact: ${Number(f.impact).toFixed(3)}<br>
                        <small>${f.explanation}</small>
                    </li>
                `;
            });
            html += `
                    </ul>
                </div>
            `;
        }

        if (second && second.model) {
            const secProb = (second.probability !== undefined && second.probability !== null)
                ? (Number(second.probability) * 100).toFixed(2) + "%"
                : "N/A";
            html += `
                <div style="background:#e8f5e9;padding:12px;border-radius:6px;margin-bottom:15px;">
                    <h6><b>Second Opinion (from ${second.model})</b></h6>
                    <p><b>Prediction:</b> ${second.prediction}</p>
                    <p><b>Confidence:</b> ${secProb}</p>
                </div>
            `;
        }

        if (medical_warning) {
            html += `<div class="alert alert-danger">${medical_warning}</div>`;
        }

        if (food) {
            if (food.liver_friendly || food.avoid) {
                html += `
                    <div style="background:#f0f8ff;padding:12px;border-radius:6px;margin-top:12px;">
                        <h6><b>Food Recommendations</b></h6>
                        <p><b>Recommended:</b></p>
                        <ul>${(food.liver_friendly || []).map(x => `<li>${x}</li>`).join('')}</ul>
                        <p><b>Avoid:</b></p>
                        <ul>${(food.avoid || []).map(x => `<li>${x}</li>`).join('')}</ul>
                        <p style="font-size:0.9em;color:#666">${food.notes || ''}</p>
                    </div>
                `;
            } else if (food.note || food.notes) {
                html += `
                    <div style="background:#eef;padding:10px;border-radius:6px;margin-top:12px;">
                        <p>${food.note || food.notes}</p>
                    </div>`;
            }
        }

        $('#predictModalBody').html(html);
        const predictModal = new bootstrap.Modal(document.getElementById('predictModal'));
        predictModal.show();
    })
    .catch(err => {
        console.error(err);
        alert("Prediction request failed. Check console.");
    });
});




    });
</script>

<?php include 'includes/footer.php'; ?>
