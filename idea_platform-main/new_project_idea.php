<?php
session_start(); // Start the session to access session variables like user_id
require_once 'db.php'; // Assuming db.php is in the same directory and sets up $conn (a mysqli object)

// --- REMOVING MOCK USER DATA --- 
// // --- Mock user data for now (if not logged in or for testing) ---
// if (!isset($_SESSION['user_id'])) {
//     $_SESSION['user_id'] = 1; // Mock current user ID if not set
// }
// // --- End of Mock User Data ---

// Fetch Project Types from Database using mysqli
$project_types_from_db = [];
if ($conn) { // Check if $conn is a valid mysqli connection object
    $sql_project_types = "SELECT id, name FROM project_types ORDER BY name ASC";
    if ($stmt = $conn->prepare($sql_project_types)) {
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $project_types_from_db = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } else {
            error_log("Error executing project types query: " . $stmt->error);
        }
    } else {
        error_log("Error preparing project types query: " . $conn->error);
    }
} else {
    error_log("Database connection not established. Check db.php.");
}

// Fetch Users from Database using mysqli
$users_for_dropdown = [];
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

if ($conn && $current_user_id) {
    // Use ? as placeholder for mysqli
    $sql_users = "SELECT id, username FROM users WHERE id != ? ORDER BY username ASC";
    if ($stmt = $conn->prepare($sql_users)) {
        // Bind parameters: 'i' for integer
        $stmt->bind_param('i', $current_user_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $users_for_dropdown = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } else {
            error_log("Error executing users query: " . $stmt->error);
        }
    } else {
        error_log("Error preparing users query: " . $conn->error);
    }
}

// Fallback users if necessary (e.g. DB error or no users found)
if (empty($users_for_dropdown) && !$conn) { 
    $users_for_dropdown = [
        ['id' => 0, 'username' => 'Sample User 1 (DB Error)'],
        ['id' => 0, 'username' => 'Sample User 2 (DB Error)'],
    ];
} elseif (empty($users_for_dropdown) && $conn) {
    // This case means query ran but no other users found
    // The dropdown will show "No other users found..." from the HTML part
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Project Idea</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
        }

        #app-header {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            background-color: #fff;
            border-bottom: 1px solid #e0e0e0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .logo-image {
            height: 40px;
            display: block;
        }

        #hamburger-icon {
            font-size: 24px;
            cursor: pointer;
            margin-left: 20px;
            color: #555;
        }

        #sidebar {
            height: calc(100vh - 70px);
            width: 60px; /* Adjusted width */
            position: fixed;
            top: 70px;
            left: -60px; /* Adjusted for new width */
            background-color: #ffffff;
            border-right: 1px solid #e0e0e0;
            transition: left 0.3s ease;
            z-index: 999;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        #sidebar.open {
            left: 0;
        }

        #sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        #sidebar li a {
            display: flex;
            align-items: center;
            justify-content: center; /* Center icon if text is removed */
            padding: 15px 10px; /* Adjust padding if only icon */
            color: #555;
            text-decoration: none;
            font-size: 1em;
            border-left: 3px solid transparent;
        }
        
        #sidebar li .icon {
            margin-right: 0; /* Remove margin if no text */
            /* font-size: 1.2em; */
            width: 24px; /* Example size for icons */
            height: 24px; /* Example size for icons */
        }

        #sidebar li a:hover {
            background-color: #f0f0f0;
        }

        #sidebar li.active a {
            color: #007bff;
            font-weight: bold;
            border-left-color: #007bff;
            background-color: #e7f3ff;
        }

        #main-content {
            padding: 20px;
            margin-top: 70px; 
            margin-left: 80px; 
            transition: margin-left 0.3s ease;
        }

        #main-content h1 {
            color: #333;
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 20px; /* Added margin for spacing */
        }

        /* Form specific styles */
        .form-container {
            background-color: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 700px; /* Limit form width */
            margin: 0 auto; /* Center form if page is wider */
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #555;
        }

        .form-group input[type="text"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
        }
        
        .form-group input[type="text"]:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }


        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        /* Ensure this style only targets multi-select for project members if Type becomes single select */
        #project-members[multiple] {
            min-height: 120px; /* Make multi-select taller */
        }

        .form-group .image-upload-container {
            display: flex;
            align-items: center;
        }
        
        .form-group .image-upload-container label {
             margin-right: 10px; /* Spacing between "Image" label and button */
             margin-bottom: 0; /* Align with button */
        }

        .form-group input[type="file"] {
            /* Basic styling for file input, can be enhanced */
            border: none; /* Remove default border */
            padding: 0;
        }

        .add-button { /* Style for the "+ Add" button for image */
            padding: 8px 15px;
            background-color: #6c757d; /* Grey color */
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
        }

        .add-button:hover {
            background-color: #5a6268;
        }


        .create-button {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            display: block; /* Make it block to take full width or use margin auto for centering */
            width: 100%; /* Make button full width of its container */
            text-align: center;
        }

        .create-button:hover {
            background-color: #0056b3;
        }
        
        /* Styles for member search input */
        /* .member-search-input { --- This style is no longer needed --- 
            margin-bottom: 8px; 
            width: 100%; 
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        } */

        .logout-link { /* Copied from dashboard.php for consistency */
            margin-left: auto;
            padding: 0 10px;
            color: #555;
            text-decoration: none;
            font-size: 0.9em;
            display: flex;
            align-items: center;
        }
        .logout-link img {
            height: 24px; /* Adjust size as needed */
            width: 24px;  /* Adjust size as needed */
        }
        .logout-link:hover {
            color: #007bff;
        }

    </style>
</head>
<body>
    <div id="app-header">
        <img src="images/logo.png" alt="App Logo" class="logo-image">
        <span id="hamburger-icon">&#9776;</span>
        <a href="logout.php" class="logout-link"><img src="images/logout_icon.png" alt="Logout" title="Logout"></a>
    </div>

    <nav id="sidebar">
        <ul>
            <li<?php echo strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false ? ' class="active"' : ''; ?>><a href="dashboard.php" title="Dashboard"><img src="images/dashboard_icon.png" alt="Dashboard" class="icon"></a></li>
            <li class="active"><a href="new_project_idea.php" title="New Project Idea"><img src="images/new_idea.png" alt="New Idea" class="icon" style="width:24px; height:24px;"></a></li>
            <li><a href="edit_profile.php" title="Profile"><img src="images/profile_icon.png" alt="Profile" class="icon" style="width:24px; height:24px;"></a></li>
        </ul>
    </nav>

    <main id="main-content">
        <div class="form-container">
            <h1>New Project Idea</h1>
            <form action="submit_project_idea.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="project-type">Type</label>
                    <select id="project-type" name="project_type[]" multiple="multiple" required style="width: 100%;"> 
                        <?php if (!empty($project_types_from_db)): ?>
                            <?php foreach ($project_types_from_db as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['id']); // Use ID as value ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>Could not load project types.</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="project-title">Project title</label>
                    <input type="text" id="project-title" name="project_title" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" required></textarea>
                </div>

                <div class="form-group">
                    <label for="project-members">Add project members</label>
                    <select id="project-members" name="project_members[]" multiple style="width: 100%;">
                        <?php foreach ($users_for_dropdown as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                <?php echo htmlspecialchars($user['username']); // Or $user['name'] ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($users_for_dropdown)): ?>
                            <option value="" disabled>No other users found or error loading users.</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="image-upload-container">
                        <label for="image-upload-input">Image</label>
                        <button type="button" class="add-button" onclick="triggerFileUpload();">+ Add</button>
                        <input type="file" id="image-upload-input" name="image" accept="image/*" style="display: none;">
                        <span id="file-chosen-text" style="margin-left: 10px;">No file chosen</span>
                    </div>
                </div>

                <button type="submit" class="create-button">Create</button>
            </form>
        </div>
    </main>

    <script>
        const hamburger = document.getElementById('hamburger-icon');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        const sidebarWidth = 60; // Adjusted sidebar width
        const mainContentMarginClosed = '80px'; 
        // const mainContentMarginOpen = sidebarWidth + 20 + 'px'; // Old calculation, now direct

        // Function to toggle sidebar and adjust main content margin
        function toggleSidebar() {
            sidebar.classList.toggle('open');
            if (sidebar.classList.contains('open')) {
                mainContent.style.marginLeft = sidebarWidth + 'px';
            } else {
                mainContent.style.marginLeft = mainContentMarginClosed;
            }
        }

        hamburger.addEventListener('click', toggleSidebar);

        // Initial state for sidebar (if it should be closed by default and controlled by hamburger)
        if (!sidebar.classList.contains('open')) {
            mainContent.style.marginLeft = mainContentMarginClosed;
        } else {
             mainContent.style.marginLeft = sidebarWidth + 'px'; // If it's somehow open by default
        }

        function triggerFileUpload() {
            console.log("Add button clicked, attempting to trigger file input.");
            document.getElementById('image-upload-input').click();
        }

        const imageUploadInput = document.getElementById('image-upload-input');
        const fileChosenText = document.getElementById('file-chosen-text');

        if (imageUploadInput && fileChosenText) {
            imageUploadInput.addEventListener('change', function(){
                console.log("File input change event fired.");
                if (this.files && this.files.length > 0) {
                    console.log("File selected: " + this.files[0].name);
                    fileChosenText.textContent = this.files[0].name;
                } else {
                    console.log("No file selected or file selection cancelled.");
                    fileChosenText.textContent = 'No file chosen';
                }
            });
        } else {
            console.error("Image upload input or file chosen text span not found!");
        }

    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2 for Project Types
            $('#project-type').select2({
                placeholder: "Select project type(s)",
                allowClear: true
            });

            // Initialize Select2 for Project Members
            $('#project-members').select2({
                placeholder: "Search and add project members",
                allowClear: true
            });
        });
    </script>
</body>
</html> 