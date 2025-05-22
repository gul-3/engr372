<?php
include 'db.php';

$username = $_POST['username'];
$password = $_POST['password'];
$fullname = $_POST['fullname'];
$email = $_POST['email'];
$birthday = $_POST['birthday'];

// Check if username or email already exists
$check = "SELECT * FROM users WHERE username='$username' OR email='$email'";
$result = $conn->query($check);

if ($result->num_rows > 0) {
  // Redirect back with an error message
  header("Location: signup.html?error=exists");
  exit();
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$sql = "INSERT INTO users (username, password, fullname, email, birthday)
        VALUES ('$username', '$hashed_password', '$fullname', '$email', '$birthday')";

if ($conn->query($sql) === TRUE) {
  header("Location: login.html");
  exit();
} else {
  echo "Error: " . $conn->error;
}

$conn->close();
?>
