<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';

require_login();
if (!is_admin()) {
    header("Location: /index.php");
    exit;
}
$current_page = basename($_SERVER['PHP_SELF']);
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<style>
.dashboard-row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 20px;
}
.card {
    flex: 1 1 calc(50% - 20px); /* two cards per row on larger screens */
    background: #fff;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 0 15px rgba(0,0,0,0.05);
    min-width: 280px;
}
.card h5, .card h3 {
    margin-bottom: 15px;
}
.chart-container {
    position: relative;
    width: 100%;
    height: 260px;
}
.data-table-container {
    overflow-x: auto;
}

/* Patient search dropdown */
.autocomplete {
    position: relative;
    display: inline-block;
    width: 100%;
}
.autocomplete input {
    width: 100%;
    padding: 10px;
    font-size: 14px;
    border-radius: 5px;
    border: 1px solid #ccc;
}
.autocomplete-items {
    position: absolute;
    border: 1px solid #d4d4d4;
    border-top: none;
    z-index: 99;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 200px;
    overflow-y: auto;
    background: #fff;
}
.autocomplete-items div {
    padding: 10px;
    cursor: pointer;
    background-color: #fff;
    border-bottom: 1px solid #d4d4d4;
}
.autocomplete-items div:hover {
    background-color: #e9e9e9;
}
</style>

<div class="content">

    <!-- First row: Disease & Gender -->
    <div class="dashboard-row">
        <div class="card">
            <h5>Disease Distribution</h5>
            <div class="chart-container"><canvas id="distChart"></canvas></div>
        </div>
        <div class="card">
            <h5>Gender Breakdown</h5>
            <div class="chart-container"><canvas id="genderChart"></canvas></div>
        </div>
    </div>

    <!-- Second row: Risk & Top Factors -->
    <div class="dashboard-row">
        <div class="card">
            <h5>Risk Levels</h5>
            <div class="chart-container"><canvas id="riskChart"></canvas></div>
        </div>
        <div class="card">
            <h5>Top Factors</h5>
            <div class="chart-container"><canvas id="factorChart"></canvas></div>
        </div>
    </div>

    <!-- Third row: Patient Trend (full width) -->
    <div class="dashboard-row">
        <div class="card" style="flex: 1 1 100%;">
            <h5>Patient Probability Trend</h5>
            <div class="autocomplete">
                <input id="patientInput" type="text" placeholder="Search or select patient ID">
            </div>
            <div class="chart-container" style="margin-top:15px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Fourth row: Recent Predictions (full width) -->
    <div class="dashboard-row">
        <div class="card" style="flex: 1 1 100%;">
            <h3>Recent Prediction Activity</h3>
            <div class="data-table-container">
                <table class="data-table display nowrap" style="width:100%">
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Prediction</th>
                            <th>Probability</th>
                            <th>Risk Level</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pdo = getPDO();
                        $sql = "SELECT patient_id, age, gender, predicted_label AS prediction, probability, risk_level, created_at 
                                FROM predictions 
                                ORDER BY created_at DESC 
                                LIMIT 10";
                        $stmt = $pdo->query($sql);

                        if ($stmt === false) {
                            $errorInfo = $pdo->errorInfo();
                            $errorMsg = ($errorInfo[0] !== '00000')
                                ? "SQL Error: " . htmlspecialchars($errorInfo[2])
                                : "Database query failed.";
                            echo "<tr><td colspan='7' style='text-align:center;'>{$errorMsg}</td></tr>";
                        } else {
                            $has_rows = false;
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $decrypted_id = decrypt_data($row['patient_id']);
                                $decrypted_gender = decrypt_data($row['gender'] ?? '');
                                $decrypted_age = decrypt_data($row['age'] ?? '');
                                $decrypted_prediction = decrypt_data($row['prediction'] ?? '');
                                $probability = htmlspecialchars($row['probability']);
                                $risk_level = htmlspecialchars($row['risk_level']);
                                $created_at = htmlspecialchars($row['created_at']);

                                echo "<tr>
                                        <td>{$decrypted_id}</td>
                                        <td>{$decrypted_age}</td>
                                        <td>{$decrypted_gender}</td>
                                        <td>{$decrypted_prediction}</td>
                                        <td>{$probability}%</td>
                                        <td>{$risk_level}</td>
                                        <td>{$created_at}</td>
                                     </tr>";
                                $has_rows = true;
                            }
                            if (!$has_rows) {
                                echo "<tr><td colspan='7' style='text-align:center;'>No prediction records found.</td></tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- JS Dependencies -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
let data = {};

async function loadDashboardData() {
    try {
        const res = await fetch('predictions.php?ajax=1');
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        data = await res.json();

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

        // Risk Levels
        new Chart(document.getElementById('riskChart'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(data.riskCounts),
                datasets: [{
                    data: Object.values(data.riskCounts),
                    backgroundColor:['#28a745','#ffc107','#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });

        // Top Factors
        new Chart(document.getElementById('factorChart'), {
            type: 'bar',
            data: {
                labels: Object.keys(data.topFactors),
                datasets: [{
                    label:'Top Factors',
                    data: Object.values(data.topFactors),
                    backgroundColor:'#36A2EB'
                }]
            },
            options: {
                indexAxis:'y',
                responsive:true,
                plugins:{ legend:{display:false} }
            }
        });

        initAutocomplete(document.getElementById('patientInput'), data.patients);

    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

// Autocomplete function
function initAutocomplete(inp, arr) {
    let currentFocus;

    inp.addEventListener("input", function(e) {
        const val = this.value;
        closeAllLists();
        if (!val) { renderPatientTrend([], ''); return false; }
        currentFocus = -1;

        const listDiv = document.createElement("DIV");
        listDiv.setAttribute("id", this.id + "autocomplete-list");
        listDiv.setAttribute("class", "autocomplete-items");
        this.parentNode.appendChild(listDiv);

        arr.forEach(p => {
            if (p.id.substr(0,val.length).toUpperCase() === val.toUpperCase()) {
                const itemDiv = document.createElement("DIV");
                itemDiv.innerHTML = "<strong>" + p.id.substr(0,val.length) + "</strong>" + p.id.substr(val.length);
                itemDiv.innerHTML += "<input type='hidden' value='" + p.id + "'>";
                itemDiv.addEventListener("click", function(e) {
                    inp.value = this.getElementsByTagName("input")[0].value;
                    renderPatientTrend(arr, inp.value);
                    closeAllLists();
                });
                listDiv.appendChild(itemDiv);
            }
        });
    });

    inp.addEventListener("keydown", function(e) {
        let x = document.getElementById(this.id + "autocomplete-list");
        if (x) x = x.getElementsByTagName("div");
        if (e.keyCode == 40) { currentFocus++; addActive(x); }
        else if (e.keyCode == 38) { currentFocus--; addActive(x); }
        else if (e.keyCode == 13) {
            e.preventDefault();
            if (currentFocus > -1) {
                if (x) x[currentFocus].click();
            }
        }
    });

    function addActive(x) {
        if (!x) return false;
        removeActive(x);
        if (currentFocus >= x.length) currentFocus=0;
        if (currentFocus<0) currentFocus=x.length-1;
        x[currentFocus].classList.add("autocomplete-active");
    }
    function removeActive(x) {
        for (let i=0;i<x.length;i++) x[i].classList.remove("autocomplete-active");
    }
    function closeAllLists(elmnt) {
        let x = document.getElementsByClassName("autocomplete-items");
        for (let i=0;i<x.length;i++) {
            if (elmnt!=x[i] && elmnt!=inp) x[i].parentNode.removeChild(x[i]);
        }
    }
    document.addEventListener("click", function (e) { closeAllLists(e.target); });
}

// Render patient trend chart
function renderPatientTrend(patients, patientId) {
    const patientData = patients.filter(p => p.id === patientId);
    const ctx = document.getElementById('trendChart').getContext('2d');
    if (window.trendChartInstance) window.trendChartInstance.destroy();

    const pointColors = patientData.map(p => {
        if (p.risk_level==='High') return '#dc3545';
        if (p.risk_level==='Medium') return '#ffc107';
        return '#28a745';
    });

    window.trendChartInstance = new Chart(ctx, {
        type:'line',
        data:{
            labels: patientData.map(p=>p.created_at),
            datasets:[{
                label: `Probability Trend - ${patientId}`,
                data: patientData.map(p=>p.probability),
                borderColor:'#007BFF',
                backgroundColor:'rgba(0,123,255,0.2)',
                fill:true,
                tension:0.3,
                pointRadius:6,
                pointHoverRadius:8,
                pointBackgroundColor:pointColors
            }]
        },
        options:{
            responsive:true,
            plugins:{
                legend:{display:true},
                tooltip:{
                    callbacks:{
                        label:function(context){
                            const idx=context.dataIndex;
                            const p=patientData[idx];
                            return `Prediction: ${p.prediction}, Probability: ${(p.probability*100).toFixed(2)}%, Risk: ${p.risk_level}`;
                        }
                    }
                }
            },
            scales:{ y:{ beginAtZero:true, max:1, ticks:{ callback: v => (v*100).toFixed(1)+'%' } } }
        }
    });
}

loadDashboardData();

// Initialize DataTable
$(document).ready(function () {
    $('.data-table').DataTable({
        pageLength: 5,
        lengthMenu: [5,10,25,50],
        ordering:true,
        searching:true,
        responsive:true,
        dom:'Bfrtip',
        buttons:['copy','csv','excel','pdf','print'],
        language:{ search:"_INPUT_", searchPlaceholder:"Search predictions..." }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
