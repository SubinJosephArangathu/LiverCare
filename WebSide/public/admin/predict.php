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

.form-row {
    display: flex;
    flex-direction: column;
}

.form-row label {
    font-weight: 500;
    color: #0047AB;
    margin-bottom: 5px;
}

form input, form select {
    padding: 8px 10px;
    border-radius: 6px;
    border: 1px solid #0047AB;
    font-size: 0.95rem;
    outline: none;
    transition: 0.3s;
}
form input:focus, form select:focus {
    border-color: #228B22;
    box-shadow: 0 0 5px rgba(34,139,34,0.3);
}

form .btn {
    grid-column: 1 / -1;
    background-color: #0047AB;
    color: white;
    border: none;
    padding: 10px 15px;
    font-size: 1rem;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s;
}
form .btn:hover {
    background-color: #00398f;
}
.bg-warning {
  --bs-bg-opacity: 1;
  background-color: rgb(5, 41, 78) !important;
}
.text-dark {
  --bs-text-opacity: 1;
  color: rgb(207, 217, 227) !important;
}
.btn-warning {
  --bs-btn-color: #000;
  --bs-btn-bg: #26b377;}

  .btn-secondary {
  --bs-btn-color: #fff;
  --bs-btn-bg: #d72a22;}
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
            <input name="age" type="number" placeholder="Age" required />
        </div>

        <div class="form-row">
            <label>Gender</label>
            <select name="gender">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>

        <div class="form-row"><label>ALB (g/dL)</label><input name="ALB" type="number" step="0.01" required /></div>
        <div class="form-row"><label>ALP (U/L)</label><input name="ALP" type="number" step="0.01" required /></div>
        <div class="form-row"><label>ALT (U/L)</label><input name="ALT" type="number" step="0.01" required /></div>
        <div class="form-row"><label>AST (U/L)</label><input name="AST" type="number" step="0.01" required /></div>
        <div class="form-row"><label>BIL (umol/L)</label><input name="BIL" type="number" step="0.01" required /></div>
        <div class="form-row"><label>CHE (U/L)</label><input name="CHE" type="number" step="0.01" required /></div>
        <div class="form-row"><label>CHOL (mmol/L)</label><input name="CHOL" type="number" step="0.01" required /></div>
        <div class="form-row"><label>CREA (umol/L)</label><input name="CREA" type="number" step="0.01" required /></div>
        <div class="form-row"><label>GGT (U/L)</label><input name="GGT" type="number" step="0.01" required /></div>
        <div class="form-row"><label>PROT (g/L)</label><input name="PROT" type="number" step="0.01" required /></div>

        <button type="submit" class="btn">Predict</button>
    </form>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title">Confirm Submission</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to send this patient data for prediction?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="confirmSubmit" class="btn btn-warning">Yes, Send</button>
      </div>
    </div>
  </div>
</div>

<!-- Prediction Result Modal -->
<div class="modal fade" id="predictionModal" tabindex="-1" aria-labelledby="predictionModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="predictionModalLabel">Prediction Result</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="predictionModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

    // Show confirmation modal
    const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    confirmModal.show();
});

document.getElementById('confirmSubmit').addEventListener('click', async () => {
    const confirmModalEl = document.getElementById('confirmModal');
    const confirmModal = bootstrap.Modal.getInstance(confirmModalEl);
    confirmModal.hide();

    // Prepare payload
    const payload = {
        Age: parseFloat(formPayload.age || 0),
        Sex: formPayload.gender,
        ALB: parseFloat(formPayload.ALB || 0),
        ALP: parseFloat(formPayload.ALP || 0),
        ALT: parseFloat(formPayload.ALT || 0),
        AST: parseFloat(formPayload.AST || 0),
        BIL: parseFloat(formPayload.BIL || 0),
        CHE: parseFloat(formPayload.CHE || 0),
        CHOL: parseFloat(formPayload.CHOL || 0),
        CREA: parseFloat(formPayload.CREA || 0),
        GGT: parseFloat(formPayload.GGT || 0),
        PROT: parseFloat(formPayload.PROT || 0),
        patient_id: formPayload.patient_id
    };

    try {
        const res = await fetch('predict_action.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if(json.success){
            const risk = json.risk_level || 'Unknown';
            let bgColor = 'lightgreen', textColor = 'darkgreen';
            if (risk.toLowerCase().includes('moderate')) { bgColor = '#fff8e0'; textColor = '#7a6500'; }
            if (risk.toLowerCase().includes('high')) { bgColor = '#ffecec'; textColor = '#a40000'; }

            // Top factors
            let factorsHTML = '';
            if(json.top_factors && Array.isArray(json.top_factors)){
                factorsHTML = `<h5 style="color:#0047AB;">Top Factors</h5><ul>`;
                json.top_factors.forEach(f=>{
                    const desc = f.impact>0 ? `<span style="color:#b30000;">Higher ${f.feature} increases risk</span>` : `<span style="color:#007f00;">Lower ${f.feature} decreases risk</span>`;
                    factorsHTML += `<li><b>${f.feature}</b>: ${desc} (impact ${f.impact.toFixed(3)})</li>`;
                });
                factorsHTML += '</ul>';
            }

            // Explanation (handle string or array)
            let explanationHTML = '';
            if(json.explanation_text){
                explanationHTML = `<h5 style="color:#0047AB;">Explanation</h5>`;
                if(typeof json.explanation_text === 'string'){
                    explanationHTML += `<p>${json.explanation_text}</p>`;
                } else if(Array.isArray(json.explanation_text)){
                    explanationHTML += '<ul>';
                    json.explanation_text.forEach(item=>{
                        if(typeof item==='object' && item!==null){
                            explanationHTML += `<li>${item.feature?`<b>${item.feature}</b>: `:''}${item.text||JSON.stringify(item)}</li>`;
                        } else {
                            explanationHTML += `<li>${item}</li>`;
                        }
                    });
                    explanationHTML += '</ul>';
                }
            }

            const html = `
                <div style="background:${bgColor};color:${textColor};padding:12px;border-radius:6px;">
                    <h5>${risk} Risk</h5>
                    <p><b>Prediction:</b> ${json.prediction}</p>
                    <p><b>Confidence:</b> ${(json.probability*100).toFixed(2)}%</p>
                    ${factorsHTML}
                    ${explanationHTML}
                </div>
            `;

            document.getElementById('predictionModalBody').innerHTML = html;
            const predictionModal = new bootstrap.Modal(document.getElementById('predictionModal'));
            predictionModal.show();

        } else {
            alert(json.error||'Prediction failed');
        }

    } catch(err){
        console.error(err);
        alert('Request failed. See console for details.');
    }
});
</script>
