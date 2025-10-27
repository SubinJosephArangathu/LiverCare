<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if(!is_admin()) { header("Location: /index.php"); exit; }

function loadCSV($file){
    $rows = array_map('str_getcsv', file($file));
    $header = array_shift($rows);
    $data = [];
    foreach($rows as $r){
        $data[] = array_combine($header, $r);
    }
    return [$header, $data];
}

$mode = $_GET["mode"] ?? "test";

if($mode === "full"){
    [$header, $dataset] = loadCSV(__DIR__ . "/liver_disease.csv");
} else {
    [$header, $dataset] = loadCSV(__DIR__ . "/model_test_results.csv");
}

// Remove only technical columns (but keep Category for full dataset)
$clean_header = [];
foreach($header as $h){
    if($mode === "test"){
        if(!in_array($h, ["label","true_labelwise","Correct?"])){
            $clean_header[] = $h;
        }
    } else {
        // Full dataset: keep Category
        if(!in_array($h, ["label"])){
            $clean_header[] = $h;
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Dataset</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<h2>Dataset Viewer</h2>

<select id="datasetMode" onchange="location='admin_dataset.php?mode='+this.value">
    <option value="test" <?=($mode=="test"?"selected":"")?>>Test Dataset ✅</option>
    <option value="full" <?=($mode=="full"?"selected":"")?>>Full Dataset 📊</option>
</select>

<table border="1" width="100%">
<tr>
<?php foreach($clean_header as $h) echo "<th>$h</th>"; ?>
<th>Predict</th>
</tr>

<?php foreach($dataset as $row): ?>
<tr>
<?php foreach($clean_header as $h): ?>
<td><?= $row[$h] ?></td>
<?php endforeach; ?>

<td>
<button class="predict-btn"
 data-row='<?= json_encode($row) ?>'>
Predict
</button>
</td>
</tr>
<?php endforeach; ?>
</table>

<script>
document.querySelectorAll(".predict-btn").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
        let row = JSON.parse(btn.dataset.row);
        
        let confirm = await Swal.fire({
            title:"Confirm Prediction?",
            icon:"question",
            showCancelButton:true
        });
        if(!confirm.isConfirmed) return;

        let res = await fetch("admin_predict_row.php", {
            method:"POST",
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(row)
        });

        let out = await res.json();
        if(out.success){
            Swal.fire("✅ Predicted!", "Result: "+out.prediction, "success");
        } else {
            Swal.fire("❌ Error", out.error, "error");
        }
    });
});
</script>
</body>
</html>
