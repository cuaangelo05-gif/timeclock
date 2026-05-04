<?php
include 'config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Invalid request.';
    } else {
        // Fetching the input values
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);

        if (empty($username) || empty($password) || empty($full_name)) {
            $message = 'All fields are required.';
        } elseif (strlen($password) < 6) {
            $message = 'Password must be at least 6 characters.';
        } else {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Prepare the SQL statement
            try {
                $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, password_plain, fullname) VALUES (?, ?, ?, ?)');
                $stmt->execute([$username, $hashed_password, $password, $full_name]);
                $message = 'Admin user created successfully! You can now <a href="admin_login.php">log in</a>.';
            } catch (Exception $e) {
                $message = 'Error creating admin user: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin User</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        form { max-width: 400px; }
        input { display: block; margin: 10px 0; padding: 8px; width: 100%; }
        button { padding: 10px; background: #007bff; color: white; border: none; cursor: pointer; }
        .message { margin: 10px 0; padding: 10px; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <h1>Create Admin User</h1>
    <?php if ($message): ?>
        <div class="message <?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <form method="POST" action="create_admin_user.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <label for="username">Username:</label>
        <input type="text" id="username" name="username" required>

        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required>

        <label for="full_name">Full Name:</label>
        <input type="text" id="full_name" name="full_name" required>

        <button type="submit">Create Admin User</button>
    </form>
</body>
</html>
