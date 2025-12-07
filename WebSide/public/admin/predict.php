<?php 
require_once __DIR__ . '/../includes/auth_check.php';
require_login();
if(!is_admin()) {
    header("Location: /index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<style>
/* ... keep your existing styles unchanged ... */
.content {
    padding: 25px;
    background: #f8f9fa;
    min-height: calc(100vh - 60px);
}
form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px 30px;
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
}
.form-row { display:flex; flex-direction:column; }
.form-row label { font-weight:500; color:#0047AB; margin-bottom:5px; }
form input, form select { padding:8px 10px; border-radius:6px; border:1px solid #0047AB; font-size:0.95rem; outline:none; transition:0.3s; }
form input:focus, form select:focus { border-color:#228B22; box-shadow:0 0 5px rgba(34,139,34,0.3); }
form .btn { grid-column:1 / -1; background-color:#0047AB; color:white; border:none; padding:10px 15px; font-size:1rem; border-radius:6px; cursor:pointer; transition:0.3s; }
form .btn:hover { background-color:#00398f; }
.bg-warning { background-color: rgb(5, 41, 78) !important; }
.text-dark { color: rgb(207, 217, 227) !important; }
.btn-warning { background-color: #26b377; }
.btn-secondary { background-color: #d72a22; }
</style>

<div class="content">
    <h2>Liver Disease Prediction</h2>

    <form id="predictForm">
        <div class="form-row">
            <label>Patient ID</label>
            <input name="patient_id" placeholder="Enter Patient ID" required />
        </div>

        <div class="form-row">
            <label>Age</label>
            <input name="Age" type="number" required />
        </div>

        <div class="form-row">
            <label>Gender</label>
            <select name="Gender" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>

        <!-- Dataset Fields -->
        <div class="form-row"><label>Total Bilirubin (TB)</label><input name="TB" type="number" step="0.01" required /></div>
        <div class="form-row"><label>Direct Bilirubin (DB)</label><input name="DB" type="number" step="0.01" required /></div>
        <div class="form-row"><label>Alkaline Phosphotase (Alkphos)</label><input name="Alkphos" type="number" required /></div>
        <div class="form-row"><label>Alamine Aminotransferase (Sgpt)</label><input name="Sgpt" type="number" required /></div>
        <div class="form-row"><label>Aspartate Aminotransferase (Sgot)</label><input name="Sgot" type="number" required /></div>
        <div class="form-row"><label>Total Proteins (TP)</label><input name="TP" type="number" step="0.01" required /></div>
        <div class="form-row"><label>Albumin (ALB)</label><input name="ALB" type="number" step="0.01" required /></div>
        <div class="form-row"><label>Albumin/Globulin Ratio (A/G Ratio)</label><input name="A/G Ratio" type="number" step="0.01" required /></div>

        <button type="submit" class="btn">Predict</button>
    </form>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title">Confirm Submission</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to send this patient data for prediction?
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="confirmSubmit" class="btn btn-warning">Yes, Send</button>
      </div>
    </div>
  </div>
</div>

<!-- Prediction Modal -->
<div class="modal fade" id="predictionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Prediction Result</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="predictionModalBody"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
let formPayload = null;

document.getElementById('predictForm').addEventListener('submit', (e) => {
    e.preventDefault();
    formPayload = Object.fromEntries(new FormData(e.target).entries());

    const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    confirmModal.show();
});

document.getElementById('confirmSubmit').addEventListener('click', async () => {

    const confirmModal = bootstrap.Modal.getInstance(document.getElementById('confirmModal'));
    confirmModal.hide();

    // Build payload for Flask: use A_G key (model expects A_G or variants)
    const payload = {
        patient_id: formPayload.patient_id,
        Age: parseFloat(formPayload.Age),
        Gender: formPayload.Gender,
        TB: parseFloat(formPayload.TB),
        DB: parseFloat(formPayload.DB),
        Alkphos: parseFloat(formPayload.Alkphos),
        Sgpt: parseFloat(formPayload.Sgpt),
        Sgot: parseFloat(formPayload.Sgot),
        TP: parseFloat(formPayload.TP),
        ALB: parseFloat(formPayload.ALB),
        A_G: parseFloat(formPayload["A/G Ratio"]) // <-- send as A_G
    };

    try {
        const res = await fetch("predict_action.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(payload)
        });

        const json = await res.json();

        if (!json.success) {
            alert(json.error || "Prediction failed");
            return;
        }

        // --- Read probability with fallback ---
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

        // Build human-friendly risk description
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

        // Compose HTML
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
            const secProb = (second.probability !== undefined && second.probability !== null) ? (Number(second.probability) * 100).toFixed(2) + "%" : "N/A";
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
                html += `<div style="background:#eef;padding:10px;border-radius:6px;margin-top:12px;"><p>${food.note || food.notes}</p></div>`;
            }
        }

        document.getElementById("predictionModalBody").innerHTML = html;
        const predictionModal = new bootstrap.Modal(document.getElementById("predictionModal"));
        predictionModal.show();

    } catch (err) {
        console.error(err);
        alert("Request failed. Check console.");
    }
});
</script>
