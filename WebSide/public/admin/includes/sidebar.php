<div class="sidebar">
  <h2 class="logo">Admin</h2>
  <a href="/admin/dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
  <a href="/admin/dataset.php" class="<?= $current_page == 'dataset.php' ? 'active' : '' ?>">Dataset & Validation</a>
  <a href="/admin/predict.php" class="<?= $current_page == 'predict.php' ? 'active' : '' ?>">Predict</a>
  <a href="/admin/users.php" class="<?= $current_page == 'users.php' ? 'active' : '' ?>">Users</a>
  <a href="/logout.php" class="logout">Logout</a>
</div>

<div class="main-content">
  <div class="topbar">
    <h2>Clinical Liver Disease Admin Panel</h2>
  </div>
