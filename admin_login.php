<?php
// admin_login.php - username + password login using admins table (passwords hashed with password_hash)
require 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// If already logged in, redirect
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $dest = $_GET['redirect'] ?? 'admin.php';
    header('Location: ' . $dest);
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pw = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? $redirect;

    if ($username === '' || $pw === '') {
        $error = 'Enter username and password.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, password_hash, fullname FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin || !isset($admin['password_hash']) || !password_verify($pw, $admin['password_hash'])) {
                // small delay to slow brute-force attempts
                usleep(300000);
                $error = 'Incorrect username or password.';
            } else {
                // Successful login
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = $admin['username'];
                $_SESSION['admin_fullname'] = $admin['fullname'] ?? '';
                session_regenerate_id(true);
                header('Location: ' . $redirect);
                exit;
            }
        } catch (Exception $e) {
            // Do not disclose DB errors to the user
            $error = 'Login failed due to server error.';
            // optionally log $e->getMessage() to a file
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin login</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="admin.css">
  <style>
    body{font-family:Inter,system-ui,Arial;margin:0;background:#f6f8fa}
    .login-wrap{max-width:420px;margin:80px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,36,0.06)}
    h1{margin:0 0 12px;font-size:18px}
    .error{color:#b91c1c;margin:8px 0}
    .field{margin:10px 0}
    input[type="text"], input[type="password"]{width:100%;padding:10px;border:1px solid #e6eef8;border-radius:8px}
    button{padding:10px 12px;border-radius:8px;border:0;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}
    .note{font-size:13px;color:#64748b;margin-top:8px}
  </style>
</head>
<body>
  <main class="login-wrap" role="main">
    <h1>Admin login</h1>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post" action="admin_login.php">
      <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
      <div class="field">
        <label class="sr-only" for="username">Username</label>
        <input id="username" name="username" type="text" placeholder="Username" required autofocus>
      </div>
      <div class="field">
        <label class="sr-only" for="password">Password</label>
        <input id="password" name="password" type="password" placeholder="Password" required>
      </div>
      <div class="field"><button type="submit">Sign in</button></div>
    </form>
   </main>
</body>
</html>