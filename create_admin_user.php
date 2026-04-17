<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fetching the input values
    $username = $_POST['username'];
    $password = $_POST['password'];
    $full_name = $_POST['full_name'];

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Prepare the SQL statement
    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, fullname) VALUES (?, ?, ?)');
    $stmt->execute([$username, $hashed_password, $full_name]);

    // Execute the statement and check for success
    if ($stmt->execute()) {
        echo 'Admin user created successfully!';
    } else {
        echo 'Error: ' . $stmt->error;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin User</title>
</head>
<body>
    <h1>Create Admin User</h1>
    <form method="POST" action="create_admin_user.php">
        <label for="username">Username:</label><br>
        <input type="text" id="username" name="username" required><br><br>
        <label for="password">Password:</label><br>
        <input type="password" id="password" name="password" required><br><br>
        <label for="full_name">Full Name:</label><br>
        <input type="text" id="full_name" name="full_name" required><br><br>
        <input type="submit" value="Create Admin User">
    </form>
</body>
</html>
