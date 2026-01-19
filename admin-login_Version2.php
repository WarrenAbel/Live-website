<?php
// admin-login.php
session_start();

// Change these to your own secure values
const ADMIN_USERNAME = 'bendcutadmin';
const ADMIN_PASSWORD = 'ChangeThisToAStrongPassword123!';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if ($user === ADMIN_USERNAME && $pass === ADMIN_PASSWORD) {
        $_SESSION['is_admin'] = true;
        header('Location: admin-quotes.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Login | Bend Cut Send</title>
</head>
<body>
  <h1>Admin Login</h1>
  <?php if ($error): ?>
    <p style="color:#c62828;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <form method="post" action="admin-login.php">
    <label>Username
      <input type="text" name="username" required>
    </label>
    <br>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <br><br>
    <button type="submit">Log in</button>
  </form>
</body>
</html>