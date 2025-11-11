<?php
// public/admin_predict_row.php
require_once __DIR__ . '/../includes/auth_check.php';
require_login();
if(!is_admin()){
    echo json_encode(["success"=>false,"error"=>"Unauthorized"]); exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/db.php';

// ------------------------------
// Read and Validate JSON Input
// ------------------------------
$input = json_decode(file_get_contents("php://input"), true);
if(!$input){ 
    echo json_encode(["success"=>false,"error"=>"Invalid input"]); 
    exit; 
}

// ------------------------------
// Normalize input keys
// ------------------------------
$map = function($k){
    $k_low = strtolower(trim($k));
    $mapping = [
        'age' => 'Age', 'sex' => 'Sex', 'gender' => 'Sex',
        'alb' => 'ALB', 'albu' => 'ALB',
        'alp' => 'ALP', 'alt' => 'ALT', 'ast' => 'AST', 'bil' => 'BIL',
        'che' => 'CHE', 'chol' => 'CHOL', 'ch' => 'CHOL',
        'crea' => 'CREA', 'creatinine' => 'CREA', 'creatinine_mg' => 'CREA',
        'ggt' => 'GGT', 'prot' => 'PROT', 'protein' => 'PROT',
        'id' => 'ID', 'row' => 'ID', 'rowid' => 'ID'
    ];
    return $mapping[$k_low] ?? $k;
};

// Normalize keys
$normalized = [];
foreach($input as $k => $v){
    $nk = $map($k);
    $normalized[$nk] = $v;
}

// ------------------------------
// Normalize Gender properly
// ------------------------------
$sex_input = isset($normalized['Sex']) ? strtolower(trim($normalized['Sex'])) : '';
if(in_array($sex_input, ['m','male','1','true','t','yes'])){
    $gender_plain = 'Male';
} elseif(in_array($sex_input, ['f','female','0','false','n','no'])){
    $gender_plain = 'Female';
} else {
    $gender_plain = 'Unknown';
}

// ------------------------------
// Prepare payload for Flask API
// ------------------------------
$payload = [
    'Age'  => isset($normalized['Age']) ? floatval($normalized['Age']) : 0.0,
    'Sex'  => $sex_input,
    'ALB'  => isset($normalized['ALB']) ? floatval($normalized['ALB']) : 0.0,
    'ALP'  => isset($normalized['ALP']) ? floatval($normalized['ALP']) : 0.0,
    'ALT'  => isset($normalized['ALT']) ? floatval($normalized['ALT']) : 0.0,
    'AST'  => isset($normalized['AST']) ? floatval($normalized['AST']) : 0.0,
    'BIL'  => isset($normalized['BIL']) ? floatval($normalized['BIL']) : 0.0,
    'CHE'  => isset($normalized['CHE']) ? floatval($normalized['CHE']) : 0.0,
    'CHOL' => isset($normalized['CHOL']) ? floatval($normalized['CHOL']) : 0.0,
    'CREA' => isset($normalized['CREA']) ? floatval($normalized['CREA']) : 0.0,
    'GGT'  => isset($normalized['GGT']) ? floatval($normalized['GGT']) : 0.0,
    'PROT' => isset($normalized['PROT']) ? floatval($normalized['PROT']) : 0.0,
    'patient_id' => isset($normalized['ID']) ? 'DS_' . $normalized['ID'] : ('DS_' . time())
];

if(empty($payload['patient_id'])){
    echo json_encode(["success"=>false,"error"=>"Missing patient id"]); exit;
}

// ------------------------------
// Call Flask API for prediction
// ------------------------------
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
    echo json_encode([
        "success"=>false,
        "error"=>"Model server error",
        "http_code"=>$code,
        "curl_err"=>$curl_err,
        "payload"=>$payload,
        "raw"=>$result
    ]);
    exit;
}

$resp = json_decode($result, true);
if(!$resp || empty($resp['prediction'])){
    echo json_encode(["success"=>false,"error"=>"Invalid model response","raw"=>$result]); exit;
}

// ------------------------------
// Extract result fields safely
// ------------------------------
$prediction = $resp['prediction'] ?? 'Unknown';
$probability = $resp['probability'] ?? 0.0;
$risk_level = $resp['risk_level'] ?? 'Unknown';
$model_version = $resp['model_version'] ?? 'v1.0';
$top_factors = json_encode($resp['top_factors'] ?? []);
$explanation_text = json_encode($resp['explanation_text'] ?? []);

// ------------------------------
// Save prediction in DB
// ------------------------------
$pdo = getPDO();
try {
    $stmt = $pdo->prepare("
        INSERT INTO predictions (
            user_id, patient_id, age, gender,
            ALB, ALP, ALT, AST, BIL, CHE, CHOL, CREA, GGT, PROT,
            predicted_label, probability, risk_level, top_factors, explanation_text, model_version, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ref_id = isset($normalized['ID']) ? intval($normalized['ID']) : NULL;
    $stmt = $pdo->prepare("
    INSERT INTO predictions (
        user_id, patient_id, age, gender,
        ALB, ALP, ALT, AST, BIL, CHE, CHOL, CREA, GGT, PROT,
        predicted_label, probability, risk_level, top_factors, explanation_text, model_version, source
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    $_SESSION['user_id'],                        // user_id
    encrypt_data(strval($payload['patient_id'])),// patient_id
    encrypt_data(strval($payload['Age'])),       // age
    encrypt_data($gender_plain),                 // gender
    encrypt_data(strval($payload['ALB'])),       // ALB
    encrypt_data(strval($payload['ALP'])),       // ALP
    encrypt_data(strval($payload['ALT'])),       // ALT
    encrypt_data(strval($payload['AST'])),       // AST
    encrypt_data(strval($payload['BIL'])),       // BIL
    encrypt_data(strval($payload['CHE'])),       // CHE
    encrypt_data(strval($payload['CHOL'])),      // CHOL
    encrypt_data(strval($payload['CREA'])),      // CREA
    encrypt_data(strval($payload['GGT'])),       // GGT
    encrypt_data(strval($payload['PROT'])),      // PROT
    encrypt_data(strval($prediction)),           // predicted_label
    floatval($probability),                      // probability
    $risk_level,                                 // risk_level
    $top_factors,                                // top_factors (JSON string)
    $explanation_text,                           // explanation_text (JSON string)
    $model_version,                              // model_version
    'dataset'                                    // source
]);
} catch (PDOException $e){
    echo json_encode(["success"=>false,"error"=>"DB insert failed","details"=>$e->getMessage()]); exit;
}

// ------------------------------
// Return response to frontend
// ------------------------------
echo json_encode([
    "success" => true,
    "prediction" => $prediction,
    "probability" => $probability,
    "risk_level" => $risk_level,
    "top_factors" => json_decode($top_factors, true),
    "explanation_text" => json_decode($explanation_text, true),
    "model_version" => $model_version,
    "gender" => $gender_plain
]);
