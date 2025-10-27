<?php
require_once __DIR__ . '/../includes/db.php';
// session_start();

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT id, password, role FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if(!$user) {
    header("Location: index.php?error=" . urlencode("Invalid credentials"));
    exit;
}

if(password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    if($user['role'] === 'admin') header("Location: /admin_dashboard.php");
    else header("Location: /staff_predict.php");
    exit;
} else {
    header("Location: index.php?error=" . urlencode("Invalid credentials"));
    exit;
}
