<?php
// public/predict_action.php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$input = json_decode(file_get_contents('php://input'), true);
if(!$input){
    echo json_encode(['success'=>false,'error'=>'Invalid JSON input']); 
    exit;
}

// Construct payload for Flask model (order must match model feature_order)
$payload = [
    'patient_id' => $input['patient_id'] ?? ('P_' . time()),
    'Age' => floatval($input['Age'] ?? $input['age'] ?? 0.0),
    'Sex' => $input['Sex'] ?? $input['gender'] ?? '',
    'ALB' => floatval($input['ALB'] ?? 0.0),
    'ALP' => floatval($input['ALP'] ?? 0.0),
    'ALT' => floatval($input['ALT'] ?? 0.0),
    'AST' => floatval($input['AST'] ?? 0.0),
    'BIL' => floatval($input['BIL'] ?? 0.0),
    'CHE' => floatval($input['CHE'] ?? 0.0),
    'CHOL' => floatval($input['CHOL'] ?? 0.0),
    'CREA' => floatval($input['CREA'] ?? 0.0),
    'GGT' => floatval($input['GGT'] ?? 0.0),
    'PROT' => floatval($input['PROT'] ?? 0.0)
];

// --- CURL to Flask ---
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => FLASK_PREDICT_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 15
]);
$res = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

// Log raw Flask response (for debugging)
$log_dir = __DIR__ . '/../logs';
if (!file_exists($log_dir)) mkdir($log_dir, 0777, true);
file_put_contents($log_dir . '/flask_response.json', $res);

if ($res === false || $http_code !== 200) {
    echo json_encode([
        'success' => false,
        'error' => 'Model server error',
        'http_code' => $http_code,
        'curl_error' => $curl_err,
        'resp' => $res
    ]);
    exit;
}

$resp = json_decode($res, true);
if (!$resp || !isset($resp['prediction'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid model response', 'raw' => $res]);
    exit;
}

// --- Extract values safely ---
$prediction       = $resp['prediction'] ?? 'Unknown';
$probability      = floatval($resp['probability'] ?? 0.0);
$risk_level       = $resp['risk_level'] ?? '';
$top_factors      = $resp['top_factors'] ?? [];
$explanation_text = $resp['explanation_text'] ?? '';
$model_version    = $resp['model_version'] ?? '';
$record_hash      = $resp['hash'] ?? null;

// --- Handle second opinion ---
$second_opinion = $resp['second_opinion'] ?? null;
$second_opinion_model = null;
$second_opinion_pred  = null;
$second_opinion_prob  = null;

if (is_array($second_opinion)) {
    $second_opinion_model = $second_opinion['model'] ?? null;
    $second_opinion_pred  = $second_opinion['prediction'] ?? null;
    $second_opinion_prob  = isset($second_opinion['probability']) ? floatval($second_opinion['probability']) : null;
}

// --- Save to DB ---
$pdo = getPDO();

// Ensure DB column `second_opinion` exists or use JSON field for flexibility
$sql = "INSERT INTO predictions 
(user_id, patient_id, age, gender, ALB, ALP, ALT, AST, BIL, CHE, CHOL, CREA, GGT, PROT, 
 predicted_label, probability, risk_level, top_factors, explanation_text, model_version, record_hash, source, second_opinion)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
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
        encrypt_data((string)$prediction),
        $probability,
        $risk_level,
        json_encode($top_factors),
        $explanation_text,
        $model_version,
        $record_hash,
        'staff',
        json_encode([
            'model' => $second_opinion_model,
            'prediction' => $second_opinion_pred,
            'probability' => $second_opinion_prob
        ])
    ]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false, 'error'=>'DB insert failed', 'details'=>$e->getMessage()]);
    exit;
}

// --- Respond to frontend ---
$response = [
    'success' => true,
    'prediction' => $prediction,
    'probability' => $probability,
    'risk_level' => $risk_level,
    'top_factors' => $top_factors,
    'explanation_text' => $explanation_text,
    'model_version' => $model_version
];

// Include second opinion in frontend JSON if available
if ($second_opinion_model || $second_opinion_pred) {
    $response['second_opinion'] = [
        'model' => $second_opinion_model,
        'prediction' => $second_opinion_pred,
        'probability' => $second_opinion_prob
    ];
}

echo json_encode($response);
