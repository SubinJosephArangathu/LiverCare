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
    border-color: #228B22; /* green accent */
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

        <div class="form-row">
            <label>ALB (g/dL)</label>
            <input name="ALB" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>ALP (U/L)</label>
            <input name="ALP" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>ALT (U/L)</label>
            <input name="ALT" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>AST (U/L)</label>
            <input name="AST" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>BIL (umol/L)</label>
            <input name="BIL" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>CHE (U/L)</label>
            <input name="CHE" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>CHOL (mmol/L)</label>
            <input name="CHOL" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>CREA (umol/L)</label>
            <input name="CREA" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>GGT (U/L)</label>
            <input name="GGT" type="number" step="0.01" required />
        </div>

        <div class="form-row">
            <label>PROT (g/L)</label>
            <input name="PROT" type="number" step="0.01" required />
        </div>

        <button type="submit" class="btn">Predict</button>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.getElementById('predictForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = Object.fromEntries(new FormData(e.target).entries());

    // Build payload to match model & PHP insertion
    const payload = {
        Age: parseFloat(formData.age || 0),
        Sex: formData.gender, // keep Male/Female as string
        ALB: parseFloat(formData.ALB || 0),
        ALP: parseFloat(formData.ALP || 0),
        ALT: parseFloat(formData.ALT || 0),
        AST: parseFloat(formData.AST || 0),
        BIL: parseFloat(formData.BIL || 0),
        CHE: parseFloat(formData.CHE || 0),
        CHOL: parseFloat(formData.CHOL || 0),
        CREA: parseFloat(formData.CREA || 0),
        GGT: parseFloat(formData.GGT || 0),
        PROT: parseFloat(formData.PROT || 0),
        patient_id: formData.patient_id
    };

    const conf = await Swal.fire({
        title: 'Confirm Prediction',
        text: 'Send patient data for prediction?',
        icon: 'question',
        showCancelButton: true
    });
    if(!conf.isConfirmed) return;

    try {
        const res = await fetch('predict_action.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if(json.success){
            Swal.fire({
                title: 'Prediction Result',
                html: `<b>Prediction:</b> ${json.prediction}<br>
                       <b>Confidence:</b> ${(json.probability*100).toFixed(2)}%`,
                icon: 'success'
            });
        } else {
            Swal.fire('Error', json.error || 'Prediction failed', 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Request failed. See console for details.', 'error');
        console.error(err);
    }
});
</script>
