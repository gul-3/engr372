<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantınız

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// Proje ID'sini al
if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    $_SESSION['error_message'] = "Invalid project ID.";
    header("Location: dashboard.php");
    exit;
}
$project_id_to_edit = intval($_GET['project_id']);

// Proje detaylarını, mevcut tiplerini ve üyelerini çek
$project_data = null;
$current_project_type_ids = [];
$current_project_member_ids = []; // Eğer üye özelliği varsa

if ($conn) {
    // Ana proje bilgilerini çek
    $stmt_project = $conn->prepare("SELECT * FROM project_ideas WHERE id = ?");
    $stmt_project->bind_param("i", $project_id_to_edit);
    $stmt_project->execute();
    $result_project = $stmt_project->get_result();
    if ($result_project->num_rows === 1) {
        $project_data = $result_project->fetch_assoc();
        // Güvenlik kontrolü: Proje bu kullanıcıya mı ait?
        if ($project_data['creator_user_id'] != $current_user_id) {
            $_SESSION['error_message'] = "You are not authorized to edit this project.";
            header("Location: dashboard.php");
            exit;
        }
    } else {
        $_SESSION['error_message'] = "Project not found.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_project->close();

    // Mevcut proje tiplerini çek
    $stmt_types = $conn->prepare("SELECT project_type_id FROM project_idea_types WHERE project_idea_id = ?");
    $stmt_types->bind_param("i", $project_id_to_edit);
    $stmt_types->execute();
    $result_types = $stmt_types->get_result();
    while ($row = $result_types->fetch_assoc()) {
        $current_project_type_ids[] = $row['project_type_id'];
    }
    $stmt_types->close();

    // TODO: Mevcut proje üyelerini çek (eğer varsa)
    // $stmt_members = $conn->prepare("SELECT user_id FROM project_idea_members WHERE project_idea_id = ?");
    // $stmt_members->bind_param("i", $project_id_to_edit);
    // $stmt_members->execute();
    // $result_members = $stmt_members->get_result();
    // while ($row = $result_members->fetch_assoc()) {
    //     $current_project_member_ids[] = $row['user_id'];
    // }
    // $stmt_members->close();

} else {
    $_SESSION['error_message'] = "Database connection error.";
    header("Location: dashboard.php");
    exit;
}

// Tüm proje tiplerini dropdown için çek (new_project_idea.php'den benzer)
$all_project_types_from_db = [];
if ($conn) {
    $sql_all_project_types = "SELECT id, name FROM project_types ORDER BY name ASC";
    if ($stmt_all_types = $conn->prepare($sql_all_project_types)) {
        if ($stmt_all_types->execute()) {
            $result_all_types = $stmt_all_types->get_result();
            $all_project_types_from_db = $result_all_types->fetch_all(MYSQLI_ASSOC);
            $stmt_all_types->close();
        } else {
            error_log("Error executing project types query: " . $stmt_all_types->error);
        }
    } else {
        error_log("Error preparing project types query: " . $conn->error);
    }
}

// Tüm kullanıcıları dropdown için çek (new_project_idea.php'den benzer, mevcut kullanıcı hariç)
$users_for_dropdown = [];
if ($conn) {
    $sql_users = "SELECT id, username FROM users WHERE id != ? ORDER BY username ASC";
    if ($stmt_users = $conn->prepare($sql_users)) {
        $stmt_users->bind_param('i', $current_user_id);
        if ($stmt_users->execute()) {
            $result_users = $stmt_users->get_result();
            $users_for_dropdown = $result_users->fetch_all(MYSQLI_ASSOC);
            $stmt_users->close();
        } else {
            error_log("Error executing users query: " . $stmt_users->error);
        }
    } else {
        error_log("Error preparing users query: " . $conn->error);
    }
}

// Hata mesajları ve başarı mesajları için
$error_message = $_SESSION['error_message'] ?? null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['error_message']);
unset($_SESSION['success_message']);

$user_profile_pic_path = 'uploads/profile_pics/'; // Dashboard'daki gibi
$project_image_path = 'uploads/project_images/'; // Dashboard'daki gibi
$default_project_pic = 'images/project_img_placeholder.png';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project Idea</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background-color: #f4f4f4; color: #333; }
        #app-header { display: flex; align-items: center; padding: 15px 20px; background-color: #fff; border-bottom: 1px solid #e0e0e0; position: fixed; top: 0; left: 0; right: 0; z-index: 1000; }
        .logo-image { height: 40px; display: block; }
        #hamburger-icon { font-size: 24px; cursor: pointer; margin-left: 20px; color: #555; }
        #sidebar { height: calc(100vh - 70px); width: 60px; position: fixed; top: 70px; left: -60px; background-color: #ffffff; border-right: 1px solid #e0e0e0; transition: left 0.3s ease; z-index: 999; box-sizing: border-box; display: flex; flex-direction: column; }
        #sidebar.open { left: 0; }
        #sidebar ul { list-style: none; padding: 0; margin: 0; }
        #sidebar li a { display: flex; align-items: center; justify-content: center; padding: 15px 10px; color: #555; text-decoration: none; font-size: 1em; border-left: 3px solid transparent; }
        #sidebar li .icon { margin-right: 0; width: 24px; height: 24px; }
        #sidebar li a:hover { background-color: #f0f0f0; }
        #sidebar li.active a { color: #007bff; font-weight: bold; border-left-color: #007bff; background-color: #e7f3ff; }
        #main-content { padding: 20px; margin-top: 70px; margin-left: 80px; transition: margin-left 0.3s ease; }
        #main-content h1 { color: #333; font-size: 2em; font-weight: bold; margin-bottom: 20px; }
        .form-container { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); max-width: 700px; margin: 0 auto; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #555; }
        .form-group input[type="text"], .form-group textarea, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 1em; }
        .form-group input[type="text"]:focus, .form-group textarea:focus, .form-group select:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        .form-group textarea { min-height: 100px; resize: vertical; }
        #project-members[multiple] { min-height: 120px; }
        .form-group .image-upload-container { display: flex; align-items: center; margin-bottom:10px;}
        .form-group .image-upload-container label { margin-right: 10px; margin-bottom: 0; }
        .form-group input[type="file"] { border: none; padding: 0; }
        .add-button { padding: 8px 15px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9em; }
        .add-button:hover { background-color: #5a6268; }
        .update-button { background-color: #007bff; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 1.1em; font-weight: bold; display: block; width: 100%; text-align: center; }
        .update-button:hover { background-color: #0056b3; }
        .logout-link { margin-left: auto; padding: 0 10px; color: #555; text-decoration: none; font-size: 0.9em; display: flex; align-items: center; }
        .logout-link img { height: 24px; width: 24px; }
        .logout-link:hover { color: #007bff; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .current-image-preview { margin-top: 10px; margin-bottom: 10px; }
        .current-image-preview img { max-width: 100%; height: auto; max-height: 200px; border: 1px solid #ddd; border-radius: 4px; }
        .current-image-preview p { font-size: 0.9em; color: #555; }

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
            <!-- Update active class based on current page if needed -->
            <li><a href="dashboard.php" title="Dashboard"><img src="images/dashboard_icon.png" alt="Dashboard" class="icon"></a></li>
            <li class="active"><a href="#" title="Edit Project Idea"><img src="images/new_idea.png" alt="Edit Idea" class="icon" style="width:24px; height:24px;"></a></li>
            <li><a href="edit_profile.php" title="Profile"><img src="images/profile_icon.png" alt="Profile" class="icon" style="width:24px; height:24px;"></a></li>
        </ul>
    </nav>

    <main id="main-content">
        <div class="form-container">
            <h1>Edit Project Idea</h1>

            <?php if ($error_message): ?>
                <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($project_data): ?>
            <form action="update_project_idea.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="project_id" value="<?php echo $project_id_to_edit; ?>">
                <input type="hidden" name="existing_image_filename" value="<?php echo htmlspecialchars($project_data['image_filename'] ?? ''); ?>">


                <div class="form-group">
                    <label for="project-type">Type</label>
                    <select id="project-type" name="project_type[]" multiple="multiple" required style="width: 100%;"> 
                        <?php foreach ($all_project_types_from_db as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['id']); ?>" 
                                <?php echo in_array($type['id'], $current_project_type_ids) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                         <?php if (empty($all_project_types_from_db)): ?>
                            <option value="" disabled>Could not load project types.</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="project-title">Project title</label>
                    <input type="text" id="project-title" name="project_title" value="<?php echo htmlspecialchars($project_data['project_title'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" required><?php echo htmlspecialchars($project_data['project_description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="project-members">Add project members (Optional)</label>
                    <select id="project-members" name="project_members[]" multiple style="width: 100%;">
                        <?php foreach ($users_for_dropdown as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['id']); ?>"
                                <?php /* echo in_array($user['id'], $current_project_member_ids) ? 'selected' : ''; */ ?>> 
                                <?php // Add pre-selection logic for members if $current_project_member_ids is populated ?>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($users_for_dropdown)): ?>
                            <option value="" disabled>No other users found.</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <?php if (!empty($project_data['image_filename'])): ?>
                        <div class="current-image-preview">
                            <p>Current Image:</p>
                            <img src="<?php echo $project_image_path . htmlspecialchars($project_data['image_filename']); ?>" alt="Current Project Image">
                        </div>
                    <?php endif; ?>
                    <div class="image-upload-container">
                        <label for="image-upload-input">Change Image (Optional)</label>
                        <button type="button" class="add-button" onclick="triggerFileUpload();">+ Change</button>
                        <input type="file" id="image-upload-input" name="new_image" accept="image/*" style="display: none;">
                        <span id="file-chosen-text" style="margin-left: 10px;">No new file chosen</span>
                    </div>
                </div>

                <button type="submit" class="update-button">Update Idea</button>
            </form>
            <?php else: ?>
                <p>Could not load project data for editing.</p>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const hamburger = document.getElementById('hamburger-icon');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        const sidebarWidth = 60;
        const mainContentMarginClosed = '80px';

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            mainContent.style.marginLeft = sidebar.classList.contains('open') ? sidebarWidth + 'px' : mainContentMarginClosed;
        }
        hamburger.addEventListener('click', toggleSidebar);
        mainContent.style.marginLeft = sidebar.classList.contains('open') ? sidebarWidth + 'px' : mainContentMarginClosed;

        function triggerFileUpload() {
            document.getElementById('image-upload-input').click();
        }
        const imageUploadInput = document.getElementById('image-upload-input');
        const fileChosenText = document.getElementById('file-chosen-text');
        if (imageUploadInput && fileChosenText) {
            imageUploadInput.addEventListener('change', function(){
                fileChosenText.textContent = this.files && this.files.length > 0 ? this.files[0].name : 'No new file chosen';
            });
        }
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#project-type').select2({ placeholder: "Select project type(s)", allowClear: true });
            $('#project-members').select2({ placeholder: "Search and add project members", allowClear: true });
        });
    </script>
</body>
</html> 