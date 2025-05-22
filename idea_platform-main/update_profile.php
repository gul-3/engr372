<?php
session_start();
require_once 'db.php'; // Database connection

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$upload_dir = "uploads/profile_pics/"; // Ensure this directory exists and is writable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name_surname = trim($_POST['name_surname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $company_school_name = trim($_POST['company_school_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $delete_profile_pic_flag = $_POST['delete_profile_pic_flag'] ?? '0';

    $profile_image_filename_to_save = null;
    $errors = [];

    // Basic validation
    if (empty($name_surname)) {
        $errors[] = "Name and surname are required.";
    }
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (!empty($errors)) {
        $_SESSION['profile_update_errors'] = $errors;
        header("Location: edit_profile.php");
        exit;
    }

    // Fetch current profile image filename to manage old file deletion
    $current_db_image_filename = null;
    if (isset($conn)) {
        $stmt_current_img = $conn->prepare("SELECT profile_image_filename FROM users WHERE id = ?");
        if ($stmt_current_img) {
            $stmt_current_img->bind_param("i", $user_id);
            $stmt_current_img->execute();
            $result_current_img = $stmt_current_img->get_result();
            if ($row = $result_current_img->fetch_assoc()) {
                $current_db_image_filename = $row['profile_image_filename'];
            }
            $stmt_current_img->close();
        }
    } else {
        $_SESSION['profile_update_errors'] = ["Database connection error."];
        header("Location: edit_profile.php");
        exit;
    }
    
    $profile_image_filename_to_save = $current_db_image_filename; // Default to current image

    // --- Profile Picture Logic ---
    if ($delete_profile_pic_flag === '1') {
        if (!empty($current_db_image_filename) && file_exists($upload_dir . $current_db_image_filename)) {
            unlink($upload_dir . $current_db_image_filename); // Delete old file
        }
        $profile_image_filename_to_save = null; // Set to NULL for DB
    } elseif (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['profile_pic'];
        $filename = $file['name'];
        $file_tmp_name = $file['tmp_name'];
        $file_size = $file['size'];
        $file_error = $file['error'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_ext, $allowed_extensions)) {
            if ($file_error === 0) {
                if ($file_size <= 5000000) { // Max 5MB
                    // Delete old image if exists
                    if (!empty($current_db_image_filename) && file_exists($upload_dir . $current_db_image_filename)) {
                        unlink($upload_dir . $current_db_image_filename);
                    }
                    
                    $new_filename_base = "user_" . $user_id . "_" . time();
                    $profile_image_filename_to_save = $new_filename_base . "." . $file_ext;
                    $file_destination = $upload_dir . $profile_image_filename_to_save;

                    if (!move_uploaded_file($file_tmp_name, $file_destination)) {
                        $errors[] = "Failed to upload new profile picture.";
                        $profile_image_filename_to_save = $current_db_image_filename; // Revert to old on failure
                    }
                } else {
                    $errors[] = "File is too large (Max 5MB).";
                    $profile_image_filename_to_save = $current_db_image_filename; 
                }
            } else {
                $errors[] = "Error uploading file: " . $file_error;
                $profile_image_filename_to_save = $current_db_image_filename;
            }
        } else {
            $errors[] = "Invalid file type. Allowed: jpg, jpeg, png, gif.";
            $profile_image_filename_to_save = $current_db_image_filename;
        }
    }
    // If there were errors during file processing, revert to old filename and show errors
    if (!empty($errors)) {
        $_SESSION['profile_update_errors'] = $errors;
        header("Location: edit_profile.php");
        exit;
    }

    // --- Database Update ---
    if (isset($conn)) {
        // Using `fullname` for the database column as per user's last update to edit_profile.php
        $sql = "UPDATE users SET fullname = ?, email = ?, company_school_name = ?, department = ?, profile_image_filename = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("sssssi", 
                $name_surname, 
                $email, 
                $company_school_name, 
                $department, 
                $profile_image_filename_to_save, // This can be string or NULL
                $user_id
            );

            if ($stmt->execute()) {
                $_SESSION['profile_update_success'] = "Profile updated successfully!";
                // Update session username if it changed - though username is not editable here. Email might be.
                // if ($current_email != $email) { $_SESSION['email'] = $email; } // If you store email in session
            } else {
                $_SESSION['profile_update_errors'] = ["Error updating profile: " . $stmt->error];
            }
            $stmt->close();
        } else {
            $_SESSION['profile_update_errors'] = ["Database statement preparation error: " . $conn->error];
        }
        $conn->close();
    } else {
        $_SESSION['profile_update_errors'] = ["Database connection not established."];
    }

    header("Location: edit_profile.php");
    exit;

} else {
    // Not a POST request
    header("Location: edit_profile.php");
    exit;
}
?> 