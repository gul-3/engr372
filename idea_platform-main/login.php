<?php
include 'db.php';
$login_error = ''; // Variable to store login error messages

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['identifier']) && isset($_POST['password'])) {
        $identifier = $_POST['identifier']; // username OR email
        $password = $_POST['password'];

        // Prepare statement to prevent SQL injection
        $sql = "SELECT * FROM users WHERE username=? OR email=?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ss", $identifier, $identifier);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $row = $result->fetch_assoc();
                if (password_verify($password, $row['password'])) {
                    session_start(); // Start the session before setting session variables
                    $_SESSION['user_id'] = $row['id']; // Store user ID in session
                    $_SESSION['username'] = $row['username']; // Optional: store username
                    
                    header("Location: dashboard.php"); // Redirect to dashboard
                    exit(); // Ensure script termination after redirect
                } else {
                    $login_error = "Invalid password.";
                }
            } else {
                $login_error = "User not found.";
            }
            $stmt->close();
        } else {
            // Handle prepare statement error if necessary
            $login_error = "Database error. Please try again later."; 
            // error_log("MySQLi prepare error: " . $conn->error); // Log actual error for admin
        }
    } else {
        $login_error = "Please enter both identifier and password.";
    }
} 
// The rest of the file should be the HTML for the login form.
// If your login.php currently only contains PHP, you'll need to add the HTML form here.
// For now, if a GET request or failed POST, it will show errors if any, or nothing.

// $conn->close(); // Connection should be closed after it's no longer needed. 
// If HTML form follows, close it at the very end of the script.
?>

<?php 
// Display login errors here, above or within your form
if (!empty($login_error)) {
    // This is a basic way to show errors. You might want to integrate this into your HTML form.
    echo '<p style="color:red;">' . htmlspecialchars($login_error) . '</p>';
}
?>

<?php
// If login.html content needs to be here, it should be included or outputted.
// For example, if you have login.html, you could do:
// include 'login.html'; 
// OR output the HTML directly:
?>

<!-- Example Login Form HTML (if not already in login.php or login.html) -->
<!-- You should adapt this to match your existing login.html if it's separate -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <!-- Add your CSS links here if any, e.g., from your original login.html -->
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f4f4; margin: 0; }
        .login-container { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 320px; }
        h2 { text-align: center; color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button[type="submit"] { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
        button[type="submit"]:hover { background-color: #0056b3; }
        .error-message { color: red; text-align: center; margin-bottom: 15px;}
        .signup-link { text-align: center; margin-top: 15px; font-size: 0.9em;}
        .signup-link a { color: #007bff; text-decoration: none;}
        .signup-link a:hover { text-decoration: underline;}
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Login</h2>
        <?php if (!empty($login_error)): ?>
            <p class="error-message"><?php echo htmlspecialchars($login_error); ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <input type="text" id="identifier" name="identifier" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
        <div class="signup-link">
            Don't have an account? <a href="signup.html">Sign up</a>
        </div>
    </div>
</body>
</html>

<?php
if ($conn) {
    $conn->close(); // Close connection after all HTML output that might need it.
}
?>
