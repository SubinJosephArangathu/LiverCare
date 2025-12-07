<?php
header("Content-Type: application/json");

require_once "../connection.php";

// ----------------------
// VALIDATE INPUT
// ----------------------
$required = ["Age","Gender","TB","DB","Alkphos","Sgpt","Sgot","TP","ALB","A_G"];

foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === "") {
        echo json_encode([
            "success" => false,
            "error" => "Missing field: $key"
        ]);
        exit;
    }
}

// ----------------------
// PREPARE DATA FOR API
// ----------------------
$data = [
    "Age"      => floatval($_POST["Age"]),
    "Gender"   => floatval($_POST["Gender"]), // 0/1 from UI
    "TB"       => floatval($_POST["TB"]),
    "DB"       => floatval($_POST["DB"]),
    "Alkphos"  => floatval($_POST["Alkphos"]),
    "Sgpt"     => floatval($_POST["Sgpt"]),
    "Sgot"     => floatval($_POST["Sgot"]),
    "TP"       => floatval($_POST["TP"]),
    "ALB"      => floatval($_POST["ALB"]),
    "A_G"      => floatval($_POST["A_G"])
];

// ----------------------
// SEND TO PYTHON API
// ----------------------
$api_url = "http://127.0.0.1:5000/predict";

$payload = json_encode($data);

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Content-Length: " . strlen($payload)
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// ----------------------
// ERROR CHECK API RESPONSE
// ----------------------
if ($curl_error || $http_code != 200) {
    echo json_encode([
        "success" => false,
        "error" => "Model server error",
        "http_code" => $http_code,
        "curl_error" => $curl_error,
        "resp" => $response
    ]);
    exit;
}

$resp = json_decode($response, true);

if (!$resp || isset($resp["error"])) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid response from model",
        "details" => $resp
    ]);
    exit;
}

// ----------------------
// PARSE MODEL RESPONSE
// ----------------------
$prediction_label = $resp["prediction_label"];  // "Liver Disease" / "No Liver Disease"
$confidence       = $resp["confidence_final"];
$risk_level       = $resp["risk_label"];
$explanation_text = $resp["explanation_text"];
$model_version    = $resp["model_version"];
$top_factors      = json_encode($resp["top_factors"], JSON_UNESCAPED_UNICODE);

$second_opinion   = isset($resp["second_opinion"]) ? json_encode($resp["second_opinion"], JSON_UNESCAPED_UNICODE) : NULL;

// ----------------------
// SAVE TO DATABASE
// ----------------------
$stmt = $conn->prepare("
    INSERT INTO liver_predictions (
        age, gender, TB, DB, Alkphos, Sgpt, Sgot, TP, ALB, A_G,
        predicted_label, probability, risk_level,
        top_factors, model_version, explanation_text, second_opinion
    )
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$stmt->bind_param(
    "ddddddddddsdssss",
    $data["Age"], $data["Gender"], $data["TB"], $data["DB"], $data["Alkphos"],
    $data["Sgpt"], $data["Sgot"], $data["TP"], $data["ALB"], $data["A_G"],
    $prediction_label, $confidence, $risk_level,
    $top_factors, $model_version, $explanation_text, $second_opinion
);

$ok = $stmt->execute();

if (!$ok) {
    echo json_encode([
        "success" => false,
        "error" => "DB insert failed",
        "details" => $stmt->error
    ]);
    exit;
}

// ----------------------
// RETURN SUCCESS RESPONSE
// ----------------------
echo json_encode([
    "success" => true,
    "prediction" => $prediction_label,
    "confidence" => $confidence,
    "risk_level" => $risk_level,
    "top_factors" => $resp["top_factors"],
    "explanation_text" => $explanation_text,
    "second_opinion" => $resp["second_opinion"] ?? null,
    "model_version" => $model_version
]);

?>
