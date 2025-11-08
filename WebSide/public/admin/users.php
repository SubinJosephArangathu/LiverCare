<?php
// admin_users.php

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
if (!is_admin()) {
    header("Location: /index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$pdo = getPDO();

$message = null;
$message_type = null;

// Handle Add User Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'] ?? 'staff';

    if ($username && $password) {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->execute([$username]);

            if ($checkStmt->rowCount() > 0) {
                $_SESSION['flash_message'] = '⚠️ User already exists.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->execute([$username, $password_hash, $role]);

                $_SESSION['flash_message'] = '✅ User added successfully.';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (PDOException $e) {
             $_SESSION['flash_message'] = '❌ Database error occurred.';
            $_SESSION['flash_type'] = 'danger';
         die("Database Error: " . $e->getMessage());
  
    }
    } else {
        $_SESSION['flash_message'] = '⚠️ Please fill all required fields.';
        $_SESSION['flash_type'] = 'warning';
    }

    header("Location: users.php");
    exit;
}

// Flash Messages
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Fetch Users
$users = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Users | Admin Panel</title>
    <?php include 'includes/header.php'; ?>

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

    <style>
        .btn-add-user {
            background-color: #003366;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn-add-user:hover {
            background-color: #0052a3;
            transform: translateY(-1px);
        }

        /* Fix DataTable Header Theme */
        .dataTables_wrapper .dataTables_scrollHead th {
            background-color: #003366 !important;
            color: white !important;
        }
    </style>
</head>

<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="content">
        <div class="container-fluid py-4">

            <?php if ($message): ?>
                <div id="flashMessage" class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>


            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-semibold text-primary">👥 Manage Users</h3>
                <button  id="openModalBtn" class="btn-add-user" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    ➕ Add User
                </button>
        
            </div>

            <div class="card shadow-sm p-3">
                <div class="table-responsive">
                    <table id="usersTable" class="display nowrap table table-striped table-bordered" style="width:100%">
                        <thead class="text-center">
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= htmlspecialchars($u['id']) ?></td>
                                    <td><?= htmlspecialchars($u['username']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'success' ?>">
                                            <?= ucfirst(htmlspecialchars($u['role'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($u['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Include Add User Modal -->
    <?php include 'includes/modal_add_user.php'; ?>

    <?php include 'includes/footer.php'; ?>

    <!-- JS Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    $(document).ready(function() {
        $('#usersTable').DataTable({
            pageLength: 10,
            lengthMenu: [5, 10, 25, 50],
            responsive: true,
            dom: 'lBfrtip',
            buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search users..."
            }
        });
    });
    
    const modal = document.getElementById("addUserModal");
    const openBtn = document.getElementById("openModalBtn");
    const closeBtn = document.querySelector(".close");

    openBtn.onclick = () => {
        modal.style.display = "flex";
        setTimeout(() => modal.classList.add("show"), 10);
    };

    closeBtn.onclick = () => {
        modal.classList.remove("show");
        setTimeout(() => modal.style.display = "none", 300);
    };

    window.onclick = (e) => {
        if (e.target === modal) {
            modal.classList.remove("show");
            setTimeout(() => modal.style.display = "none", 300);
        }
    };

document.addEventListener('DOMContentLoaded', () => {
  const flash = document.getElementById('flashMessage');
  if (flash) {
    setTimeout(() => {
      // Smooth fade out
      flash.classList.remove('show');
      flash.classList.add('fade');
      setTimeout(() => flash.remove(), 500); // Remove from DOM after fade
    }, 2000); // 2 seconds
  }
});
</script>

</body>
</html>