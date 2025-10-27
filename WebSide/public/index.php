<?php
// public/index.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
// session_start();
if(!empty($_SESSION['user_id'])){
    if($_SESSION['role']=='admin') header("Location: /admin_dashboard.php");
    else header("Location: /staff_predict.php");
    exit;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Login - LiverCare</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="login-bg">
  <div class="login-box">
    <h2>LiverCare</h2>
    <?php if(!empty($_GET['error'])): ?>
      <div class="error"><?=htmlspecialchars($_GET['error'])?></div>
    <?php endif; ?>
    <form method="post" action="login_action.php">
      <input type="text" name="username" placeholder="Username" required />
      <input type="password" name="password" placeholder="Password" required />
      <button type="submit" class="btn">Login</button>
    </form>
  </div>
</body>
</html>
