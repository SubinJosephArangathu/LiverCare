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

// Build payload for Flask
$payload = [
    'patient_id' => $input['patient_id'] ?? ('P_' . time()),
    'Age'        => floatval($input['Age'] ?? $input['age'] ?? 0.0),
    'Gender'     => $input['Gender'] ?? $input['gender'] ?? '',
    'TB'         => floatval($input['TB'] ?? 0.0),
    'DB'         => floatval($input['DB'] ?? 0.0),
    'Alkphos'    => floatval($input['Alkphos'] ?? 0.0),
    'Sgpt'       => floatval($input['Sgpt'] ?? 0.0),
    'Sgot'       => floatval($input['Sgot'] ?? 0.0),
    'TP'         => floatval($input['TP'] ?? 0.0),
    'ALB'        => floatval($input['ALB'] ?? 0.0),
    // send as A_G so Flask (and model) accept it (Flask accepts A_G, AG_Ratio etc.)
    'A_G'        => floatval($input['A_G'] ?? $input['A/G Ratio'] ?? $input['AG_Ratio'] ?? 0.0)
];

// Send to Flask API
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

// Log Flask response for debugging
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

// Extract outputs (be tolerant of different key names)
$prediction       = $resp['prediction'] ?? 'Unknown';
$probability      = floatval($resp['probability_primary'] ?? $resp['probability'] ?? $resp['disease_probability'] ?? $resp['confidence_original'] ?? 0.0);
$risk_level       = $resp['risk_level'] ?? '';
$top_factors      = $resp['top_factors'] ?? [];
$explanation_text = $resp['explanation_text'] ?? ($resp['explanation'] ?? '');
$model_version    = $resp['model_version'] ?? '';
$record_hash      = $resp['hash'] ?? null;

// second opinion
$second_opinion   = $resp['second_opinion'] ?? null;
$second_op_model  = $second_opinion['model'] ?? null;
$second_op_pred   = $second_opinion['prediction'] ?? null;
$second_op_prob   = isset($second_opinion['probability']) ? floatval($second_opinion['probability']) : null;

// Save to DB
$pdo = getPDO();

// column name for AG ratio in DB - adjust if your DB column differs
$ag_db_column = 'AG_Ratio'; // keep as in your DB

$sql = "INSERT INTO predictions 
(user_id, patient_id, age, gender, TB, DB, Alkphos, Sgpt, Sgot, TP, ALB, {$ag_db_column},
 predicted_label, probability, risk_level, top_factors, explanation_text, model_version, record_hash, source, second_opinion)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        encrypt_data($payload['patient_id']),
        encrypt_data((string)$payload['Age']),
        encrypt_data((string)$payload['Gender']),
        encrypt_data((string)$payload['TB']),
        encrypt_data((string)$payload['DB']),
        encrypt_data((string)$payload['Alkphos']),
        encrypt_data((string)$payload['Sgpt']),
        encrypt_data((string)$payload['Sgot']),
        encrypt_data((string)$payload['TP']),
        encrypt_data((string)$payload['ALB']),
        encrypt_data((string)$payload['A_G']),

        // predicted label might already be "No Liver Disease"/"Liver Disease"
        encrypt_data((string)$prediction),
        $probability,
        $risk_level,
        json_encode($top_factors),
        $explanation_text,
        $model_version,
        $record_hash,
        'staff',
        json_encode([
            'model' => $second_op_model,
            'prediction' => $second_op_pred,
            'probability' => $second_op_prob
        ])
    ]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false, 'error'=>'DB insert failed', 'details'=>$e->getMessage()]);
    exit;
}

// Build response to frontend (mirror useful fields)
$response = [
    'success' => true,
    'prediction' => $prediction,
    'probability' => $probability,
    'risk_level' => $risk_level,
    'top_factors' => $top_factors,
    'explanation_text' => $explanation_text,
    'model_version' => $model_version
];

if ($second_op_model || $second_op_pred) {
    $response['second_opinion'] = [
        'model' => $second_op_model,
        'prediction' => $second_op_pred,
        'probability' => $second_op_prob
    ];
}

// optionally include other returned fields if present
if (isset($resp['confidence_final'])) $response['confidence_final'] = $resp['confidence_final'];
if (isset($resp['probability_primary'])) $response['probability_primary'] = $resp['probability_primary'];
if (isset($resp['disease_probability'])) $response['disease_probability'] = $resp['disease_probability'];
if (isset($resp['food_recommendations'])) $response['food_recommendations'] = $resp['food_recommendations'];
if (isset($resp['medical_warning'])) $response['medical_warning'] = $resp['medical_warning'];

echo json_encode($response);
