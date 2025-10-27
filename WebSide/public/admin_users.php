<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
if(!is_admin()){ header("Location: /index.php"); exit; }

$pdo = getPDO();
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $username = $_POST['username'];
    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'] ?? 'staff';
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $password_hash, $role]);
    header("Location: /admin_users.php");
    exit;
}

$users = $pdo->query("SELECT id, username, role, created_at FROM users")->fetchAll();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Users</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body>
  <div class="sidebar">...menu...</div>
  <div class="content">
    <h2>Manage Users</h2>
    <form method="post">
      <input name="username" placeholder="username" required />
      <input name="password" placeholder="password" required />
      <select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select>
      <button type="submit">Add User</button>
    </form>

    <table>
      <thead><tr><th>ID</th><th>Username</th><th>Role</th><th>Created</th></tr></thead>
      <tbody>
        <?php foreach($users as $u): ?>
        <tr><td><?=$u['id']?></td><td><?=htmlspecialchars($u['username'])?></td><td><?=$u['role']?></td><td><?=$u['created_at']?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
