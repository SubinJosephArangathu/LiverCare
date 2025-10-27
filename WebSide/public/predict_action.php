<?php
// public/predict_action.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$input = json_decode(file_get_contents('php://input'), true);
if(!$input) {
    echo json_encode(['success'=>false,'error'=>'Invalid input (no JSON)']); exit;
}

// Build payload EXACTLY matching the model feature order (Age, Sex, ALB, ... PROT)
$payload = [
    'Age' => isset($input['age']) ? floatval($input['age']) : (isset($input['Age']) ? floatval($input['Age']) : 0.0),
    'Sex' => isset($input['gender']) ? (strtolower($input['gender']) === 'male' ? 1 : 0) : (isset($input['Sex']) ? (strtolower($input['Sex']) === 'male' ? 1 : 0) : 0),
    'ALB' => isset($input['ALB']) ? floatval($input['ALB']) : 0.0,
    'ALP' => isset($input['ALP']) ? floatval($input['ALP']) : 0.0,
    'ALT' => isset($input['ALT']) ? floatval($input['ALT']) : 0.0,
    'AST' => isset($input['AST']) ? floatval($input['AST']) : 0.0,
    'BIL' => isset($input['BIL']) ? floatval($input['BIL']) : 0.0,
    'CHE' => isset($input['CHE']) ? floatval($input['CHE']) : 0.0,
    'CHOL' => isset($input['CHOL']) ? floatval($input['CHOL']) : 0.0,
    'CREA' => isset($input['CREA']) ? floatval($input['CREA']) : 0.0,
    'GGT' => isset($input['GGT']) ? floatval($input['GGT']) : 0.0,
    'PROT' => isset($input['PROT']) ? floatval($input['PROT']) : 0.0,
    'patient_id' => isset($input['patient_id']) ? strval($input['patient_id']) : (isset($input['PatientID']) ? strval($input['PatientID']) : ('P_' . time()))
];

// sanity check
if (empty($payload['patient_id'])) {
    echo json_encode(['success'=>false,'error'=>'Missing patient_id']); exit;
}

// call Flask
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, FLASK_PREDICT_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$result = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if($code !== 200){
    echo json_encode(['success'=>false,'error'=>'Model server error','http_code'=>$code,'curl_err'=>$curl_err,'resp'=>$result,'payload'=>$payload]); exit;
}
$resp = json_decode($result, true);
if(!$resp || empty($resp['prediction'])){
    echo json_encode(['success'=>false,'error'=>'Invalid model response','raw'=>$result]); exit;
}

// Save encrypted row to DB (store all lab values encrypted — you chose NO to lean logging)
$pdo = getPDO();
$sql = "INSERT INTO predictions (user_id, patient_id, age, gender, ALB,ALP,ALT,AST,BIL,CHE,CHOL,CREA,GGT,PROT, predicted_label, probability, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);
try {
    $stmt->execute([
        $_SESSION['user_id'],
        encrypt_data($payload['patient_id']),
        encrypt_data((string)$payload['Age']),
        encrypt_data((string)$payload['Sex']),
        encrypt_data((string)$payload['ALB']),
        encrypt_data((string)$payload['ALP']),
        encrypt_data((string)$payload['ALT']),
        encrypt_data((string)$payload['AST']),
        encrypt_data((string)$payload['BIL']),
        encrypt_data((string)$payload['CHE']),
        encrypt_data((string)$payload['CHOL']),
        encrypt_data((string)$payload['CREA']),
        encrypt_data((string)$payload['GGT']),
        encrypt_data((string)$payload['PROT']),
        encrypt_data((string)$resp['prediction']),
        floatval($resp['probability'] ?? 0.0),
        'staff'
    ]);
} catch (PDOException $e) {
    // fallback minimal insert if schema mismatches
    $sql2 = "INSERT INTO predictions (user_id, patient_id, predicted_label, probability, source) VALUES (?, ?, ?, ?, ?)";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute([
        $_SESSION['user_id'],
        encrypt_data($payload['patient_id']),
        encrypt_data((string)$resp['prediction']),
        floatval($resp['probability'] ?? 0.0),
        'staff'
    ]);
}

echo json_encode(['success'=>true,'prediction'=>$resp['prediction'],'probability'=>$resp['probability']]);
