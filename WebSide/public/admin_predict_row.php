<?php
// public/admin_predict_row.php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if(!is_admin()){
    echo json_encode(["success"=>false,"error"=>"Unauthorized"]); exit;
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/db.php';

$input = json_decode(file_get_contents("php://input"), true);
if(!$input){ echo json_encode(["success"=>false,"error"=>"Invalid input"]); exit; }

// Normalize keys
$map = function($k){
    $k_low = strtolower(trim($k));
    if(in_array($k_low, ['age'])) return 'Age';
    if(in_array($k_low, ['sex','gender'])) return 'Sex';
    if(in_array($k_low, ['alb','albu'])) return 'ALB';
    if(in_array($k_low, ['alp'])) return 'ALP';
    if(in_array($k_low, ['alt'])) return 'ALT';
    if(in_array($k_low, ['ast'])) return 'AST';
    if(in_array($k_low, ['bil'])) return 'BIL';
    if(in_array($k_low, ['che'])) return 'CHE';
    if(in_array($k_low, ['chol','ch'])) return 'CHOL';
    if(in_array($k_low, ['crea','creatinine','creatinine_mg'])) return 'CREA';
    if(in_array($k_low, ['ggt'])) return 'GGT';
    if(in_array($k_low, ['prot','protein'])) return 'PROT';
    if(in_array($k_low, ['id','row','rowid'])) return 'ID';
    return $k;
};

// normalize
$normalized = [];
foreach($input as $k => $v){
    $nk = $map($k);
    $normalized[$nk] = $v;
}

// Build payload in model's exact expected order
$payload = [
    'Age' => isset($normalized['Age']) ? floatval($normalized['Age']) : 0.0,
    'Sex' => isset($normalized['Sex']) ? (strtolower($normalized['Sex']) === 'male' ? 1 : 0) : 0,
    'ALB' => isset($normalized['ALB']) ? floatval($normalized['ALB']) : 0.0,
    'ALP' => isset($normalized['ALP']) ? floatval($normalized['ALP']) : 0.0,
    'ALT' => isset($normalized['ALT']) ? floatval($normalized['ALT']) : 0.0,
    'AST' => isset($normalized['AST']) ? floatval($normalized['AST']) : 0.0,
    'BIL' => isset($normalized['BIL']) ? floatval($normalized['BIL']) : 0.0,
    'CHE' => isset($normalized['CHE']) ? floatval($normalized['CHE']) : 0.0,
    'CHOL' => isset($normalized['CHOL']) ? floatval($normalized['CHOL']) : 0.0,
    'CREA' => isset($normalized['CREA']) ? floatval($normalized['CREA']) : 0.0,
    'GGT' => isset($normalized['GGT']) ? floatval($normalized['GGT']) : 0.0,
    'PROT' => isset($normalized['PROT']) ? floatval($normalized['PROT']) : 0.0,
    'patient_id' => isset($normalized['ID']) ? 'DS_' . $normalized['ID'] : ('DS_' . time())
];

if(empty($payload['patient_id'])){
    echo json_encode(["success"=>false,"error"=>"Missing patient id"]); exit;
}

// call Flask API
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
    echo json_encode(["success"=>false,"error"=>"Model server error","http_code"=>$code,"curl_err"=>$curl_err,"payload"=>$payload,"raw"=>$result]); exit;
}
$resp = json_decode($result, true);
if(!$resp || empty($resp['prediction'])){
    echo json_encode(["success"=>false,"error"=>"Invalid model response","raw"=>$result]); exit;
}

// Save encrypted row (store lab values encrypted)
$pdo = getPDO();
try {
    $stmt = $pdo->prepare("INSERT INTO predictions (user_id, reference_row_id, patient_id, age, gender, ALB,ALP,ALT,AST,BIL,CHE,CHOL,CREA,GGT,PROT, predicted_label, probability, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ref_id = isset($normalized['ID']) ? intval($normalized['ID']) : NULL;
    $stmt->execute([
        $_SESSION['user_id'],
        $ref_id,
        encrypt_data(strval($payload['patient_id'])),
        encrypt_data(strval($payload['Age'])),
        encrypt_data(strval($payload['Sex'])),
        encrypt_data(strval($payload['ALB'])),
        encrypt_data(strval($payload['ALP'])),
        encrypt_data(strval($payload['ALT'])),
        encrypt_data(strval($payload['AST'])),
        encrypt_data(strval($payload['BIL'])),
        encrypt_data(strval($payload['CHE'])),
        encrypt_data(strval($payload['CHOL'])),
        encrypt_data(strval($payload['CREA'])),
        encrypt_data(strval($payload['GGT'])),
        encrypt_data(strval($payload['PROT'])),
        encrypt_data(strval($resp['prediction'])),
        floatval($resp['probability'] ?? 0.0),
        'dataset'
    ]);
} catch (PDOException $e){
    // fallback minimal
    $stmt2 = $pdo->prepare("INSERT INTO predictions (user_id, gender, patient_id, predicted_label, probability, source) VALUES (?, ?, ?, ?, ?)");
    $stmt2->execute([
        $_SESSION['user_id'],
        encrypt_data(strval($payload['Sex'])),
        encrypt_data(strval($payload['patient_id'])),
        encrypt_data(strval($resp['prediction'])),
        floatval($resp['probability'] ?? 0.0),
        'dataset'
    ]);
}

echo json_encode(["success"=>true,"prediction"=>$resp['prediction'],"probability"=>$resp['probability'] ?? 0.0]);
