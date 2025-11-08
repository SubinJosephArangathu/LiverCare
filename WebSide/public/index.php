<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Liver Disease Prediction System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

  <!-- Background Image Slider -->
  <div id="slider">
      <div class="slide" style="background-image: url('assets/img/liver1.png');"></div>
      <div class="slide" style="background-image: url('assets/img/liver2.png');"></div>
      <div class="slide" style="background-image: url('assets/img/liver3.png');"></div>
      <div class="slide" style="background-image: url('assets/img/liver4.png');"></div>
  </div>

  <!-- Overlay -->
  <div class="overlay"></div>

  <!-- Login Box -->
  <div class="login-box text-center">
      <h2 class="mb-4">LiverCare</h2>
      <form method="post" action="login_action.php">
          <div class="mb-3">
              <input type="text" name="username" class="form-control" placeholder="Username" required />
          </div>
          <div class="mb-3">
              <input type="password" name="password" class="form-control" placeholder="Password" required />
          </div>
          <button type="submit" class="btn btn-success w-100">Login</button>
      </form>
      <div class="text-center mt-3">
         
      </div>
  </div>

  <script src="assets/js/script.js"></script>
</body>
</html>
