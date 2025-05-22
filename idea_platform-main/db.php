<?php
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "group3";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
  error_log("Connection failed in db.php: " . $conn->connect_error);
  die("FATAL ERROR in db.php: Could not connect to database. " . 
      "Servername: " . htmlspecialchars($servername) . ", " .
      "Username: " . htmlspecialchars($username) . ", " .
      "DB Name: " . htmlspecialchars($dbname) . ". " .
      "Error: " . htmlspecialchars($conn->connect_error));
}
?>
