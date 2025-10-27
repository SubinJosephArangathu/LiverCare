<?php
// session_start();
require_once dirname(__DIR__) . '/includes/db.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pdo = getPDO(); // ✅ correct for PDO

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    if ($username != "" && $password != "" && ($role == "admin" || $role == "staff")) {

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        if ($stmt->execute([$username, $hashed, $role])) {
            $message = "✅ User added successfully";
        } else {
            $message = "⚠ ERROR inserting user.";
        }
    } else {
        $message = "⚠ Invalid input.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Add Test User</title>
<link rel="stylesheet" href="/assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="login-bg">

<div class="login-box">
    <h2>Add Test User</h2>

    <?php if($message != ""): ?>
    <script>
        Swal.fire({
            icon: 'info',
            title: 'Status',
            text: "<?= $message ?>"
        });
    </script>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>

        <select name="role" required>
            <option value="">Select Role</option>
            <option value="admin">Admin</option>
            <option value="staff">Staff</option>
        </select>

        <button type="submit" class="btn">Create User</button>
    </form>

    <br>
    <a href="/index.php" class="btn">Back</a>
</div>

</body>
</html>
