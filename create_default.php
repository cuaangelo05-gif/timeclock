<?php
// create_default.php - Creates default placeholder image for employees
$base64 = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAQAAAAAYLlVAAAACXBIWXMAAAsTAAALEwEAmpwYAAABF0lEQVR4nO3WwQ2CMBBF0Y8o3KQH0m3FqzqS7iAFJgkq6cE2gk0d2yK2v4z9s4m7m+8e7/ynG6gAAAAAAAAAA4GG6z8fQq0zXc2r1r9m6wHqv3Hc8G+3fP7XGZb7wG21w79r2+8GgVt4b7b2b4bq5w3j7sH1r8xw75bq9xw6r1s7n1r3r9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9o1r3o9v2Y0Y4y2wJH3VLQAAAABJRU5ErkJggg==';

echo '<html>
<head>
    <title>Create Default Image</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 50px auto; }
        .success { color: green; font-size: 18px; }
        .error { color: red; font-size: 18px; }
        .next-step { margin-top: 20px; padding: 15px; background: #f0f0f0; border-radius: 5px; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>Create Default Image</h1>';

// Decode and save the image
$data = base64_decode($base64);

// Create uploads directory if it doesn't exist
if (!is_dir(__DIR__.'/uploads')) {
    @mkdir(__DIR__.'/uploads', 0755, true);
    echo '<p>Created uploads/ directory</p>';
}

// Save the image
$filepath = __DIR__.'/uploads/default.png';
$result = file_put_contents($filepath, $data);

if ($result !== false) {
    echo '<h2 class="success">✅ default.png created successfully!</h2>';
    echo '<p>File location: <strong>uploads/default.png</strong></p>';
    echo '<p>File size: <strong>' . filesize($filepath) . ' bytes</strong></p>';
    echo '<div class="next-step">
        <h3>Next Step: Create Admin User</h3>
        <p>You need to create an admin account to log in.</p>
        <p><a href="http://localhost/timeclock/create_admin_user.php"><strong>→ Click here to create admin user</strong></a></p>
    </div>';
} else {
    echo '<h2 class="error">❌ Failed to create default.png</h2>';
    echo '<p><strong>Possible causes:</strong></p>';
    echo '<ul>
        <li>uploads/ folder doesn\'t have write permissions</li>
        <li>Disk is full</li>
        <li>File system error</li>
    </ul>';
    echo '<p><strong>Fix:</strong> Create uploads/ folder manually and set permissions to 755</p>';
    echo '<p>Then <a href="create_default.php">try again</a></p>';
}

echo '</body></html>';
?>