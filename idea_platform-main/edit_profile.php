<?php
session_start();
require_once 'db.php'; // Include the database connection file

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Determine the fallback URL for the close button
$fallback_back_url = 'dashboard.php'; // Default fallback
$back_url = $fallback_back_url;
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $referer_url_parts = parse_url($_SERVER['HTTP_REFERER']);
    $current_url_parts = parse_url($_SERVER['REQUEST_URI']);
    
    $referer_path = $referer_url_parts['path'] ?? '';
    $current_path = $current_url_parts['path'] ?? '';

    // Ensure referer is not the current page itself (or a version of it with query params)
    if (basename($referer_path) != basename($current_path)) {
        $back_url = htmlspecialchars($_SERVER['HTTP_REFERER']);
    } else {
        // If referer is the same page, use the hardcoded fallback
        $back_url = $fallback_back_url;
    }
} else {
    // If no referer, use the hardcoded fallback
    $back_url = $fallback_back_url;
}

$user_id = $_SESSION['user_id'];
$current_username = $_SESSION['username'] ?? ''; // Username from session as a fallback
$current_name_surname = ""; 
$current_email = "";        
$current_company_school_name = "";
$current_department = "";   
$current_profile_pic = null; 

// Fetch user data from the database
// Assuming your mysqli connection variable in db.php is $conn
if (isset($conn)) {
    $stmt = $conn->prepare("SELECT username, fullname, email, company_school_name, department, profile_image_filename FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user_data = $result->fetch_assoc()) {
            $current_username = $user_data['username'] ?? $current_username; // Prefer DB username
            $current_name_surname = $user_data['fullname'];
            $current_email = $user_data['email'];
            $current_company_school_name = $user_data['company_school_name'];
            $current_department = $user_data['department'];
            if (!empty($user_data['profile_image_filename'])) {
                $current_profile_pic = "uploads/profile_pics/" . htmlspecialchars($user_data['profile_image_filename']);
            } else {
                $current_profile_pic = null; // Ensure placeholder is used if no DB image
            }
        }
        $stmt->close();
    } else {
        // Handle statement preparation error if necessary
        // For now, fields will remain empty or use session username
        error_log("Failed to prepare statement to fetch user data: " . $conn->error);
    }
} else {
    // Handle DB connection error if necessary
    error_log("Database connection variable \$conn is not set in edit_profile.php after including db.php");
}

$profile_update_errors = $_SESSION['profile_update_errors'] ?? [];
$profile_update_success = $_SESSION['profile_update_success'] ?? '';

unset($_SESSION['profile_update_errors']); // Clear after displaying
unset($_SESSION['profile_update_success']); // Clear after displaying

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f4f4f4; 
            color: #333;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 20px; /* Space for fixed header */
        }

        #app-header {
            display: flex;
            align-items: center;
            justify-content: space-between; /* For title and close button */
            padding: 15px 30px;
            background-color: #fff; 
            border-bottom: 1px solid #e0e0e0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        #app-header .page-title {
            font-size: 1.8em; /* Adjusted for prominence */
            font-weight: bold;
            color: #007bff; 
        }

        #app-header .close-button {
            font-size: 2em; /* Make X larger */
            color: #555;
            text-decoration: none;
            cursor: pointer;
        }
        #app-header .close-button:hover {
            color: #000;
        }

        .profile-form-container {
            margin-top: 100px; /* Adjust based on header height */
            background-color: #fff;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px; /* Control form width */
            display: flex;
            flex-direction: column;
            align-items: center; /* Center form elements */
        }

        .profile-pic-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 25px;
        }

        .profile-pic-container {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 10px; /* Space before edit button */
            overflow: hidden; /* To contain the image */
            border: 3px solid #dee2e6;
        }

        .profile-pic-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-pic-actions-container {
            display: flex; /* Use flexbox to align buttons horizontally */
            justify-content: center; /* Center buttons */
            align-items: center;
            width: 100%; /* Take full width to center content within profile-pic-area */
            margin-top: 5px; /* A little space above the buttons */
        }

        .edit-photo-btn, .delete-photo-btn {
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            margin: 0 5px; /* Add some space between buttons */
        }
        .edit-photo-btn {
            background-color: #6c757d;
        }
        .edit-photo-btn:hover {
            background-color: #5a6268;
        }
        .delete-photo-btn {
            background-color: #dc3545; /* Red color for delete */
        }
        .delete-photo-btn:hover {
            background-color: #c82333;
        }

        .form-group {
            width: 100%;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #555;
        }

        .static-field-container {
            padding: 12px; 
            border: 1px solid #ccc;
            border-radius: 4px;
            background-color: #e9ecef; /* Indicates non-editable */
            font-size: 1em;
            width: 100%;
            box-sizing: border-box;
        }

        .static-field-container span {
            display: block; 
        }
        
        .form-group .editable-field-container {
            display: flex;
            align-items: center;
            border: 1px solid #ccc; /* Add border to container to mimic input */
            border-radius: 4px;
            padding: 10px; 
        }

        .form-group .editable-field-container input[type="text"],
        .form-group .editable-field-container input[type="email"] {
            width: calc(100% - 40px); /* Adjust width to accommodate text 'edit' icon */
            padding: 0; 
            border: none; 
            border-radius: 0;
            box-sizing: border-box;
            font-size: 1em;
            display: none; /* Initially hidden */
        }
        
        .form-group .editable-field-container input[type="text"]:focus,
        .form-group .editable-field-container input[type="email"]:focus {
            outline: none;
            box-shadow: none; 
        }

        .form-group .editable-field-container .field-text-display {
            flex-grow: 1;
            font-size: 1em;
            line-height: 1.5; 
        }

        .form-group .edit-icon {
            margin-left: 10px;
            cursor: pointer;
            font-size: 1em; 
            color: #007bff;
            white-space: nowrap; /* Prevent 'edit' text from wrapping */
        }
        
        .form-group input[type="text"].standard-input,
        .form-group input[type="email"].standard-input { 
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
        }
        
        .form-group input[type="text"].standard-input:focus,
        .form-group input[type="email"].standard-input:focus {
             border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }


        input[type="file"] { 
            display: none; 
        }

        .save-button {
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            width: 100%;
            text-align: center;
            margin-top: 10px;
        }

        .save-button:hover {
            background-color: #0056b3;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
            text-align: left;
        }
        .alert-danger {
            color: #a94442;
            background-color: #f2dede;
            border-color: #ebccd1;
        }
        .alert-success {
            color: #3c763d;
            background-color: #dff0d8;
            border-color: #d6e9c6;
        }
        .alert ul {
            margin-top: 0;
            margin-bottom: 0;
            padding-left: 20px;
        }

    </style>
</head>
<body>
    <div id="app-header">
        <span class="page-title">Edit Profile</span>
        <a href="<?php echo $back_url; ?>" class="close-button">&times;</a>
    </div>

    <div class="profile-form-container">
        <?php if (!empty($profile_update_errors)): ?>
            <div class="alert alert-danger">
                <strong>Oops! Please correct the following errors:</strong>
                <ul>
                    <?php foreach ($profile_update_errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($profile_update_success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($profile_update_success); ?>
            </div>
        <?php endif; ?>

        <form action="update_profile.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="delete_profile_pic_flag" id="delete_profile_pic_flag" value="0">
            
            <div class="profile-pic-area">
                <div class="profile-pic-container">
                    <img src="<?php echo $current_profile_pic ?? 'images/pp_placeholder.png'; ?>" alt="Profile Picture" id="profile_pic_preview">
                </div>
                <div class="profile-pic-actions-container">
                    <button type="button" class="edit-photo-btn" onclick="document.getElementById('profile_pic_upload').click();">Edit Photo</button>
                    <button type="button" class="delete-photo-btn" onclick="deleteProfilePhotoPreview()">Delete Profile Photo</button>
                </div>
                <input type="file" id="profile_pic_upload" name="profile_pic" accept="image/*" onchange="previewProfilePic(event)">
            </div>

            <!-- Username (Display Only) -->
            <div class="form-group">
                <label for="username_value">Username</label>
                <div class="static-field-container">
                    <span id="username_value"><?php echo htmlspecialchars($current_username ?? ''); ?></span>
                </div>
            </div>

            <div class="form-group">
                <label for="name_surname_display">Name Surname</label>
                <div class="editable-field-container">
                    <span id="name_surname_display" class="field-text-display"><?php echo htmlspecialchars($current_name_surname ?? ''); ?></span>
                    <input type="text" id="name_surname_input" name="name_surname" value="<?php echo htmlspecialchars($current_name_surname ?? ''); ?>" style="display:none;">
                    <span class="edit-icon" onclick="toggleEdit('name_surname', this)">edit</span>
                </div>
            </div>

            <div class="form-group">
                <label for="email_display">Email</label>
                <div class="editable-field-container">
                    <span id="email_display" class="field-text-display"><?php echo htmlspecialchars($current_email ?? ''); ?></span>
                    <input type="email" id="email_input" name="email" value="<?php echo htmlspecialchars($current_email ?? ''); ?>" style="display:none;">
                    <span class="edit-icon" onclick="toggleEdit('email', this)">edit</span>
                </div>
            </div>

            <div class="form-group">
                <label for="company_school_name">Company / School Name</label>
                <input type="text" id="company_school_name" name="company_school_name" class="standard-input" value="<?php echo htmlspecialchars($current_company_school_name ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="department">Department</label>
                <input type="text" id="department" name="department" class="standard-input" value="<?php echo htmlspecialchars($current_department ?? ''); ?>">
            </div>

            <button type="submit" class="save-button">Save Changes</button>
        </form>
    </div>

    <script>
        function previewProfilePic(event) {
            var reader = new FileReader();
            reader.onload = function(){
                var output = document.getElementById('profile_pic_preview');
                output.src = reader.result;
                document.getElementById('delete_profile_pic_flag').value = '0'; // Reset flag if new image is chosen
            };
            if (event.target.files && event.target.files[0]) {
                reader.readAsDataURL(event.target.files[0]);
            }
        }

        function deleteProfilePhotoPreview() {
            document.getElementById('profile_pic_preview').src = 'images/pp_placeholder.png';
            document.getElementById('delete_profile_pic_flag').value = '1';
            // Clear the file input field to prevent submitting an old selection
            const fileInput = document.getElementById('profile_pic_upload');
            fileInput.value = ''; // This is the standard way to clear a file input

            // For older browsers, or if the above doesn't work reliably, 
            // you might need to replace the input element:
            // fileInput.parentNode.replaceChild(fileInput.cloneNode(true), fileInput);
        }

        function toggleEdit(fieldName, clickedIcon) {
            const displayElement = document.getElementById(fieldName + '_display');
            const inputElement = document.getElementById(fieldName + '_input');
            const iconElement = clickedIcon;

            if (inputElement.style.display === 'none') {
                // Currently displaying text, switch to input mode
                displayElement.style.display = 'none';
                inputElement.style.display = 'block'; 
                inputElement.focus();
                if (iconElement) {
                    iconElement.style.display = 'none';
                }
            } else {
                // Currently displaying input, switch back to text display mode
                if (inputElement) inputElement.style.display = 'none';
                if (displayElement) displayElement.style.display = 'block'; 
                if (iconElement) {
                    iconElement.style.display = 'inline';
                }
            }
        }
    </script>
</body>
</html> 