<?php
// public/predict_action.php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

// Read input
$input = json_decode(file_get_contents('php://input'), true);
if(!$input) {
    echo json_encode(['success'=>false,'error'=>'Invalid input (no JSON)']); 
    exit;
}

// Build payload exactly matching model feature order
$payload = [
    'Age' => isset($input['age']) ? floatval($input['age']) : (isset($input['Age']) ? floatval($input['Age']) : 0.0),
    'Sex' => isset($input['gender']) ? $input['gender'] : (isset($input['Sex']) ? $input['Sex'] : 'Unknown'),
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
    'patient_id' => isset($input['patient_id']) ? strval($input['patient_id']) : ('P_' . time())
];

// sanity check
if(empty($payload['patient_id'])) {
    echo json_encode(['success'=>false,'error'=>'Missing patient_id']); 
    exit;
}

// Call Flask API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, FLASK_PREDICT_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 12);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$result = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if($code !== 200){
    echo json_encode(['success'=>false,'error'=>'Model server error','http_code'=>$code,'curl_err'=>$curl_err,'resp'=>$result,'payload'=>$payload]); 
    exit;
}

$resp = json_decode($result, true);
if(!$resp || empty($resp['prediction'])){
    echo json_encode(['success'=>false,'error'=>'Invalid model response','raw'=>$result]); 
    exit;
}

// Save to DB: lab values + gender + prediction encrypted
$pdo = getPDO();
$sql = "INSERT INTO predictions 
(user_id, patient_id, age, gender, ALB, ALP, ALT, AST, BIL, CHE, CHOL, CREA, GGT, PROT, predicted_label, probability, source)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $pdo->prepare($sql);
try {
    $stmt->execute([
        $_SESSION['user_id'],
        encrypt_data($payload['patient_id']),
        encrypt_data((string)$payload['Age']),
        encrypt_data($payload['Sex']), // gender encrypted
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
} catch(PDOException $e){
    echo json_encode(['success'=>false,'error'=>'DB insert failed','details'=>$e->getMessage()]); 
    exit;
}

echo json_encode(['success'=>true,'prediction'=>$resp['prediction'],'probability'=>$resp['probability'] ?? 0.0]);
