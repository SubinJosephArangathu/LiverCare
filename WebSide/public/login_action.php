<?php
require_once __DIR__ . '/../includes/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT id, password, role FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    header("Location: ../index.php?error=" . urlencode("Invalid credentials"));
    exit;
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];

if ($user['role'] === 'admin') {
    header("Location: ../admin/dashboard.php");
} else {
    header("Location: ../staff/dashboard.php");
}
exit;
