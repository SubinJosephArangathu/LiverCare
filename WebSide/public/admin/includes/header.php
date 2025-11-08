<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();
if(!is_admin()){ header("Location: /index.php"); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Panel | Clinical Liver Disease</title>
  <link rel="stylesheet" href="/assets/css/admin_style.css">
    <link rel="stylesheet" href="/assets/css/table_style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <div class="layout">
