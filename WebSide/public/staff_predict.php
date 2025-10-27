<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if(!is_staff()) {
    header("Location: /index.php");
    exit;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Predict - Staff</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <div class="sidebar">
    <a href="/staff_predict.php">Predict</a>
    <a href="/staff_history.php">My History</a>
    <a href="/logout.php">Logout</a>
  </div>

  <div class="content">
    <h2>Enter Patient ID & Labs</h2>

    <form id="predictForm">
      <input name="patient_id" placeholder="Patient ID" required />

      <input name="age" placeholder="Age" />
      <select name="gender">
        <option value="Male">Male</option>
        <option value="Female">Female</option>
      </select>

      <input name="ALB" placeholder="ALB (g/dL)" />
      <input name="ALP" placeholder="ALP (U/L)" />
      <input name="ALT" placeholder="ALT (U/L)" />
      <input name="AST" placeholder="AST (U/L)" />
      <input name="BIL" placeholder="BIL (umol/L)" />
      <input name="CHE" placeholder="CHE (U/L)" />
      <input name="CHOL" placeholder="CHOL (mmol/L)" />
      <input name="CREA" placeholder="CREA (umol/L)" />
      <input name="GGT" placeholder="GGT (U/L)" />
      <input name="PROT" placeholder="PROT (g/L)" />

      <button type="submit" class="btn">Predict</button>
    </form>

    <div id="result"></div>
  </div>

<script>
document.getElementById('predictForm').addEventListener('submit', async (e) => {
  e.preventDefault();

  const formData = Object.fromEntries(new FormData(e.target).entries());

  // Build payload matching Flask feature expectation
  const payload = {
    Age: parseFloat(formData.age || 0),
    Sex: formData.gender === "Male" ? 1 : 0,
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
    title: 'Confirm prediction',
    text: 'Send patient data for prediction?',
    icon: 'question',
    showCancelButton: true
  });
  if(!conf.isConfirmed) return;

  const res = await fetch('/predict_action.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });

  const json = await res.json();

  if(json.success){
    Swal.fire('Prediction Successful', 
              'Result: ' + json.prediction + 
              '\nConfidence: ' + (json.probability*100).toFixed(2) + '%', 
              'success');
    
    document.getElementById('result').innerHTML =
      `<pre>${JSON.stringify(json, null, 2)}</pre>`;

  } else {
    Swal.fire('Error', json.error || 'Prediction failed', 'error');
  }
});
</script>

</body>
</html>
