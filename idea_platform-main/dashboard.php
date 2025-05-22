<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php'; // Veritabanı bağlantısı

$project_ideas = [];
$total_projects = 0;
$database_error_message = ''; // Veritabanı hata mesajını tutacak değişken
$project_types = []; // Proje tiplerini tutacak dizi

// Proje tiplerini çek
if (isset($conn)) {
    $sql_types = "SELECT id, name FROM project_types ORDER BY name ASC";
    $types_result = $conn->query($sql_types);
    if ($types_result) {
        while ($row = $types_result->fetch_assoc()) {
            $project_types[] = $row;
        }
        $types_result->free();
    } else {
        error_log("Error fetching project types: " . $conn->error);
        // Hata durumunda $project_types boş kalacak, filtrede gösterilmeyecek
    }
}

// Veritabanı bağlantısının var olup olmadığını ve canlı olup olmadığını kontrol et
if (isset($conn) && $conn->ping()) {
    // Get current user ID for checking likes
    $current_user_id = $_SESSION['user_id'] ?? 0;

    // Get filter parameters
    $sort_by = $_GET['sort_by'] ?? 'date_desc'; // Default sort
    $selected_project_type_ids = isset($_GET['project_types']) && is_array($_GET['project_types']) ? $_GET['project_types'] : [];
    // Sanitize project type IDs to ensure they are integers
    $selected_project_type_ids = array_map('intval', $selected_project_type_ids);
    $selected_project_type_ids = array_filter($selected_project_type_ids, function($id) { return $id > 0; });

    $filter_only_my_ideas = isset($_GET['only_my_ideas']) && $_GET['only_my_ideas'] == '1';

    $sql_order_by = "ORDER BY pi.created_at DESC"; // Default order
    switch ($sort_by) {
        case 'date_asc':
            $sql_order_by = "ORDER BY pi.created_at ASC";
            break;
        case 'likes_desc':
            $sql_order_by = "ORDER BY likes_count DESC, pi.created_at DESC";
            break;
        case 'likes_asc':
            $sql_order_by = "ORDER BY likes_count ASC, pi.created_at ASC";
            break;
        case 'comments_desc':
            $sql_order_by = "ORDER BY comments_count DESC, pi.created_at DESC";
            break;
        case 'comments_asc':
            $sql_order_by = "ORDER BY comments_count ASC, pi.created_at ASC";
            break;
        // Default is 'date_desc', handled by initial $sql_order_by value
    }

    $sql_base = "SELECT 
                pi.id AS project_id, 
                pi.project_title, 
                pi.image_filename AS project_image, 
                pi.created_at, 
                pi.project_description,
                pi.creator_user_id,
                u.fullname AS creator_fullname, 
                u.department AS creator_department, 
                u.profile_image_filename AS creator_profile_pic,
                (SELECT COUNT(*) FROM project_likes pl WHERE pl.project_id = pi.id) AS likes_count,
                (SELECT COUNT(*) FROM project_comments pc WHERE pc.project_id = pi.id) AS comments_count,
                (SELECT COUNT(*) FROM project_likes pl_user WHERE pl_user.project_id = pi.id AND pl_user.user_id = {$current_user_id}) > 0 AS user_has_liked
            FROM 
                project_ideas pi 
            LEFT JOIN 
                users u ON pi.creator_user_id = u.id";

    $sql_where_clauses = [];
    $sql_joins = "";

    if ($filter_only_my_ideas && $current_user_id > 0) {
        $sql_where_clauses[] = "pi.creator_user_id = {$current_user_id}";
    }

    if (!empty($selected_project_type_ids)) {
        // Add a join to project_idea_types
        // We need to ensure that we only select project_ideas that are associated with AT LEAST ONE of the selected types.
        // This requires a subquery or a GROUP BY and HAVING clause if we were to count matches.
        // A simpler way for "any of selected types" is using IN with a subquery selecting project_idea_ids.
        $type_ids_string = implode(',', $selected_project_type_ids);
        $sql_joins .= " JOIN project_idea_types pit ON pi.id = pit.project_idea_id";
        $sql_where_clauses[] = "pit.project_type_id IN ({$type_ids_string})";
        // Since a project might match multiple selected types and this join can create duplicate rows for such projects,
        // we need to use GROUP BY to ensure each project appears only once.
        // All non-aggregated columns in the SELECT list must be in GROUP BY.
        // However, a simpler approach for this specific need (filtering and then ordering) might be to ensure the main query only lists distinct projects.
        // Let's adjust the base selection to be DISTINCT if types are filtered.
        // $sql_base = str_replace("SELECT", "SELECT DISTINCT", $sql_base); // This might be too broad, could affect counts if not careful.
        // Instead of SELECT DISTINCT on all, let's use GROUP BY on project_id which is more explicit.
        // The columns in ORDER BY must also be in GROUP BY or use aggregate functions.
        // The likes_count and comments_count are already subqueries so they are fine.
    }

    $sql = $sql_base . " " . $sql_joins;
    if (!empty($sql_where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $sql_where_clauses);
    }
    
    // If filtering by project types and joining project_idea_types, we might get duplicate project_ideas
    // if a project has multiple types that are selected. Use GROUP BY to prevent this.
    if (!empty($selected_project_type_ids)) {
        $sql .= " GROUP BY pi.id, pi.project_title, pi.image_filename, pi.created_at, pi.project_description, pi.creator_user_id, u.fullname, u.department, u.profile_image_filename"; 
        // Need to add user_has_liked to GROUP BY as well, but it's a subquery. 
        // This can get tricky with MySQL versions. Let's re-evaluate how user_has_liked is calculated or ensure the GROUP BY is sufficient.
        // For now, this GROUP BY should make project_ids unique.
        // The sorting columns (likes_count, comments_count) are derived and should work with GROUP BY pi.id.
    }

    $sql .= " " . $sql_order_by;
    
    $result = $conn->query($sql);
    
    if ($result) {
        $project_ideas = $result->fetch_all(MYSQLI_ASSOC);
        $total_projects = $result->num_rows;
        $result->free();
    } else {
        // SQL sorgu hatasını yakala
        $database_error_message = "Proje fikirleri çekilirken hata oluştu: " . $conn->error;
        error_log($database_error_message); // Sunucu hata günlüğüne yaz
    }
} else {
    // Veritabanı bağlantı sorununu belirle
    $database_error_message = "dashboard.php içinde veritabanı bağlantısı kurulamadı veya kayboldu."; // Updated message
    if (isset($conn) && !$conn->ping()) {
         $database_error_message .= " Ping başarısız. MySQL sunucusu gitmiş veya bağlantı zaman aşımına uğramış olabilir.";
    } elseif (!isset($conn)) {
         $database_error_message .= " $conn değişkeni ayarlanmamış.";
    }
    error_log($database_error_message);
}

// Bu değişken tanımlamaları DEBUG bloğundan sonra da kalabilir, sorun değil.
$user_profile_pic_path = 'uploads/profile_pics/';
$project_image_path = 'uploads/project_images/';
$default_user_pic = 'images/pp_placeholder.png';
$default_project_pic = 'images/project_img_placeholder.png'; 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f0f2f5; /* Light grey background like the image */
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
            z-index: 1000; /* Ensure header is on top */
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
            width: 60px; 
            position: fixed;
            top: 70px; 
            left: -60px; 
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
            justify-content: center; 
            padding: 15px 10px; 
            color: #555; 
            text-decoration: none;
            border-left: 3px solid transparent; 
        }
        
        #sidebar li .icon {
            margin-right: 0; 
            width: 24px; 
            height: 24px; 
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

        #main-content .dashboard-header h1 {
            color: #1c3d5a; /* Dark blue like image */
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px; /* Space between header and count */
        }
        .total-projects-count {
            font-size: 1.2em;
            color: #555;
            margin-bottom: 25px;
            padding-left: 5px; /* Align with header text */
        }
        .total-projects-count span {
            font-weight: bold;
            color: #333;
        }

        .project-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); /* Responsive 3-columnish */
            gap: 20px;
        }

        .project-card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden; /* To contain image */
            display: flex;
            flex-direction: column;
        }

        .project-card .card-user-info {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
        }

        .project-card .user-avatar img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
        }

        .project-card .user-details .user-name {
            font-weight: bold;
            color: #333;
            font-size: 0.9em;
        }

        .project-card .user-details .user-department {
            font-size: 0.8em;
            color: #777;
        }

        .project-card .project-image-container {
            width: 100%;
            padding-top: 56.25%; /* 16:9 Aspect Ratio */
            position: relative;
            background-color: #e9ecef; /* Placeholder background */
        }

        .project-card .project-image-container img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .project-card .project-image-container .placeholder-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 3em; /* Adjust as needed */
            color: #adb5bd;
        }

        .project-card .card-project-title {
            padding: 10px 15px;
            font-weight: bold;
            color: #333;
            font-size: 1.1em;
            border-top: 1px solid #eee;
        }

        .project-card-actions {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-top: 1px solid #eee;
            gap: 15px; /* Space between like and comment sections */
        }

        .action-item {
            display: flex;
            align-items: center;
            cursor: pointer;
            color: #555;
        }

        .action-item img {
            width: 20px; /* Adjust as needed */
            height: 20px; /* Adjust as needed */
            margin-right: 5px;
        }

        .action-item span {
            font-size: 0.9em;
        }

        .logout-link {
            margin-left: auto; 
            padding: 0 10px; 
            display: flex; 
            align-items: center;
        }
        .logout-link img {
            height: 24px; 
            width: 24px;  
        }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1001; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto; /* 15% from the top and centered */
            padding: 20px;
            border: 1px solid #888;
            width: 50%; /* Could be more or less, depending on screen size */
            border-radius: 8px;
            box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2),0 6px 20px 0 rgba(0,0,0,0.19);
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        
        body.modal-open {
            overflow: hidden; /* Prevent scrolling of the background */
        }

        #main-content.blurred {
            filter: blur(5px);
            pointer-events: none; /* Prevent interaction with blurred background */
        }
        /* End Modal Styles */

        /* Filter Styles */
        .filter-container {
            position: relative;
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        #filter-icon {
            cursor: pointer;
            padding: 8px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        #filter-icon img {
            width: 24px;
            height: 24px;
            display: block;
        }

        .filter-panel {
            display: none;
            position: absolute;
            top: 40px; /* İkonun altına */
            right: 0;
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            padding: 15px;
            z-index: 100;
            width: 300px;
        }

        .filter-panel.open {
            display: block;
        }

        .filter-panel h4 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.1em;
            color: #333;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 0.9em;
        }

        .filter-group select, .filter-group button {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
         .filter-group select[multiple] {
            min-height: 80px;
        }

        .filter-group button {
            background-color: #007bff;
            color: white;
            cursor: pointer;
            font-weight: bold;
        }
        .filter-group button:hover {
            background-color: #0056b3;
        }
        /* End Filter Styles */

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
            <li class="active"><a href="dashboard.php" title="Dashboard"><img src="images/dashboard_icon.png" alt="Dashboard" class="icon" style="width:24px; height:24px;"></a></li>
            <li><a href="new_project_idea.php" title="New Project Idea"><img src="images/new_idea.png" alt="New Project Idea" class="icon" style="width:24px; height:24px;"></a></li>
            <li><a href="edit_profile.php" title="Profile"><img src="images/profile_icon.png" alt="Profile" class="icon" style="width:24px; height:24px;"></a></li>
        </ul>
    </nav>

    <main id="main-content">
        <div class="dashboard-header">
            <h1>Dashboard</h1>
            <div class="total-projects-count">
                Last project ideas: <span><?php echo $total_projects; ?></span>
            </div>
        </div>
        
        <div class="filter-container">
            <div id="filter-icon" title="Filters">
                <img src="images/filter.png" alt="Filter">
            </div>
            <div class="filter-panel" id="filter-panel">
                <h4>Filter & Sort</h4>
                <form id="filter-form" method="GET" action="dashboard.php">
                    <div class="filter-group">
                        <label for="only_my_ideas_filter" style="display: flex; align-items: center; font-weight: normal;">
                            <input type="checkbox" name="only_my_ideas" id="only_my_ideas_filter" value="1" <?php echo (isset($_GET['only_my_ideas']) && $_GET['only_my_ideas'] == '1') ? 'checked' : ''; ?> style="width: auto; margin-right: 8px;">
                            Only My Ideas
                        </label>
                    </div>

                    <div class="filter-group">
                        <label for="sort_by">Sort By:</label>
                        <select name="sort_by" id="sort_by">
                            <option value="date_desc">Date (Newest First)</option>
                            <option value="date_asc">Date (Oldest First)</option>
                            <option value="likes_desc">Likes (Most)</option>
                            <option value="likes_asc">Likes (Least)</option>
                            <option value="comments_desc">Comments (Most)</option>
                            <option value="comments_asc">Comments (Least)</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="project_types_filter">Project Types:</label>
                        <select name="project_types[]" id="project_types_filter" multiple>
                            <?php if (!empty($project_types)):
                                $current_selected_types = $_GET['project_types'] ?? []; // Get currently selected types for repopulation
                                foreach ($project_types as $type):
                                    $is_selected = in_array($type['id'], $current_selected_types);
                            ?>
                                    <option value="<?php echo htmlspecialchars($type['id']); ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No types available</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button type="button" id="apply-filters-btn">Apply Filters</button>
                    </div>
                    <div class="filter-group" style="text-align: center; margin-top: 10px;">
                        <a href="dashboard.php" style="font-size: 0.9em; color: #007bff; text-decoration: none;">Clear All Filters</a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="project-gallery">
            <?php if (!empty($project_ideas)):
                foreach ($project_ideas as $idea):
                    $is_own_idea = (isset($current_user_id) && $current_user_id > 0 && isset($idea['creator_user_id']) && (int)$idea['creator_user_id'] == $current_user_id);
            ?>
                    <div class="project-card" data-project-id="<?php echo $idea['project_id']; ?>" data-project-details='<?php echo htmlspecialchars(json_encode($idea), ENT_QUOTES, 'UTF-8'); ?>'>
                        <div class="card-user-info">
                            <div class="user-avatar">
                                <a href="view_profile.php?user_id=<?php echo $idea['creator_user_id'] ?? 0; ?>" class="profile-link">
                                    <img src="<?php echo !empty($idea['creator_profile_pic']) ? $user_profile_pic_path . htmlspecialchars($idea['creator_profile_pic']) : $default_user_pic; ?>" alt="<?php echo htmlspecialchars($idea['creator_fullname'] ?? 'Unknown User'); ?>">
                                </a>
                            </div>
                            <div class="user-details">
                                <div class="user-name">
                                    <a href="view_profile.php?user_id=<?php echo $idea['creator_user_id'] ?? 0; ?>" class="profile-link" style="text-decoration: none; color: inherit;">
                                        <?php echo htmlspecialchars($idea['creator_fullname'] ?? 'Unknown User'); ?>
                                    </a>
                                </div>
                                <div class="user-department"><?php echo htmlspecialchars($idea['creator_department'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                        <div class="project-image-container">
                            <img src="<?php echo !empty($idea['project_image']) ? $project_image_path . htmlspecialchars($idea['project_image']) : $default_project_pic; ?>" alt="<?php echo htmlspecialchars($idea['project_title']); ?>">
                        </div>
                        <div class="card-project-title">
                            <?php echo htmlspecialchars($idea['project_title']); ?>
                        </div>
                        <div class="project-card-actions">
                            <div class="action-item like-action" data-project-id="<?php echo $idea['project_id']; ?>">
                                <img src="images/<?php echo $idea['user_has_liked'] ? 'liked.png' : 'not_liked.png'; ?>" alt="Like">
                                <span class="likes-count"><?php echo $idea['likes_count']; ?></span>
                            </div>
                            <div class="action-item comment-action" data-project-id="<?php echo $idea['project_id']; ?>">
                                <img src="images/comment.png" alt="Comment">
                                <span class="comments-count"><?php echo $idea['comments_count']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No project ideas found yet. Be the first to <a href="new_project_idea.php">add one</a>!</p>
            <?php endif; ?>
        </div>
    </main>

    <!-- The Modal -->
    <div id="project-detail-modal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2 id="modal-project-title" style="font-size: 24px; font-weight: bold; margin-bottom: 15px; display: block;"></h2>
                        
                        <!-- Owner Actions: Edit and Delete -->
                        <div id="modal-owner-actions" style="display: none; margin-bottom: 15px;">
                            <a href="#" id="modal-edit-button" class="button" style="display: inline-block; min-width: 70px; text-align: center; box-sizing: border-box; margin-right: 10px; text-decoration: none; background-color: #4CAF50; color: white; padding: 8px 12px; border-radius: 4px; cursor: pointer;">Edit</a>
                            <button id="modal-delete-button" class="button" style="display: inline-block; min-width: 70px; text-align: center; box-sizing: border-box; background-color: #f44336; color: white; padding: 8px 12px; border-radius: 4px; border: none; cursor: pointer;">Delete</button>
                        </div>

                        <img id="modal-project-image" src="" alt="Project Image" style="max-width: 100%; max-height: 300px; display: block; margin-bottom: 15px;">
            <p><strong>Creator:</strong> <span id="modal-creator-name"></span></p>
            <p><strong>Department:</strong> <span id="modal-creator-department"></span></p>
            <p><strong>Description:</strong> <span id="modal-project-description"></span></p>
            
            <hr>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h4>Comments (<span id="modal-comments-count">0</span>)</h4>
                <h4><a href="javascript:void(0);" id="modal-likes-link" style="text-decoration: none; color: #007bff;">Likes (<span id="modal-likes-count">0</span>)</a></h4>
            </div>
            <div id="modal-likes-list" style="display: none; max-height: 150px; overflow-y: auto; margin-bottom: 10px; border: 1px solid #eee; padding: 10px; background-color: #f9f9f9;">
                <!-- Beğenenlerin listesi buraya gelecek -->
            </div>
            <div id="modal-comments-list" style="max-height: 200px; overflow-y: auto; margin-bottom: 15px; border: 1px solid #eee; padding: 10px;">
                <!-- Comments will be loaded here -->
            </div>
            <form id="modal-comment-form">
                <input type="hidden" id="modal-comment-project-id" name="project_id">
                <textarea id="modal-comment-text" name="comment_text" placeholder="Add a comment..." required style="width: 100%; min-height: 60px; margin-bottom: 10px; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
                <button type="submit" style="padding: 8px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Post Comment</button>
            </form>
        </div>
    </div>

    <script>
        const hamburger = document.getElementById('hamburger-icon');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        const sidebarWidth = 60; 
        const mainContentMarginClosed = '80px'; 

        hamburger.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            if (sidebar.classList.contains('open')) {
                mainContent.style.marginLeft = sidebarWidth + 'px';
            } else {
                mainContent.style.marginLeft = mainContentMarginClosed;
            }
        });

        mainContent.addEventListener('click', () => {
            if (sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                mainContent.style.marginLeft = mainContentMarginClosed;
            }
        });

        // Modal JavaScript
        const modal = document.getElementById('project-detail-modal');
        const mainContentForModal = document.getElementById('main-content'); // Renamed to avoid conflict
        const bodyElement = document.body;
        const closeButton = document.getElementsByClassName('close-button')[0];

        document.querySelectorAll('.project-card').forEach(card => {
            card.addEventListener('click', function(event) {
                console.log('[DEBUG] Project card clicked. Target:', event.target);
                // Prevent modal from opening if the click was on a profile link
                if (event.target.closest('.profile-link')) {
                    console.log('[DEBUG] Clicked on a profile link, returning.');
                    return;
                }
                console.log('[DEBUG] Not a profile link, proceeding to open modal.');
                const projectDetailsJson = this.dataset.projectDetails;
                console.log('[DEBUG] Raw projectDetails JSON:', projectDetailsJson);
                try {
                    const projectDetails = JSON.parse(projectDetailsJson);
                    console.log('[DEBUG] Parsed projectDetails:', projectDetails);
                    openProjectModal(projectDetails);
                } catch (e) {
                    console.error('[DEBUG] Error parsing projectDetails JSON:', e, projectDetailsJson);
                }
            });
        });

        // Add event listeners to profile links to stop propagation
        document.querySelectorAll('.profile-link').forEach(link => {
            link.addEventListener('click', function(event) {
                event.stopPropagation();
                // Allow default navigation
            });
        });

        // Handle clicking comment icon on card to open modal
        document.querySelectorAll('.comment-action').forEach(commentButton => {
            commentButton.addEventListener('click', function(event) {
                event.stopPropagation(); // Prevent card click if already handled by like
                const projectId = this.dataset.projectId;
                // We need to fetch project details again or find a way to pass them
                // For now, let's assume we have projectDetails if the card was clicked
                // This might require a refactor if only comment icon is clicked without full card details available
                // Simplest: fetch details again based on projectId for comment icon click if not already loaded
                const card = this.closest('.project-card');
                if (card) {
                    const projectDetails = JSON.parse(card.dataset.projectDetails);
                    openProjectModal(projectDetails, true); // true to focus comment
                }
            });
        });

        function openProjectModal(projectDetails, focusComment = false) {
            console.log('[DEBUG] openProjectModal called with details:', projectDetails);
            document.getElementById('modal-project-title').textContent = projectDetails.project_title;
            document.getElementById('modal-project-image').src = projectDetails.project_image ? '<?php echo $project_image_path; ?>' + projectDetails.project_image : '<?php echo $default_project_pic; ?>';
            document.getElementById('modal-creator-name').textContent = projectDetails.creator_fullname || 'N/A';
            document.getElementById('modal-creator-department').textContent = projectDetails.creator_department || 'N/A';
            document.getElementById('modal-project-description').textContent = projectDetails.project_description || 'No description provided.';
            document.getElementById('modal-comment-project-id').value = projectDetails.project_id;
            document.getElementById('modal-likes-count').textContent = projectDetails.likes_count || 0; 

            console.log('[DEBUG] Before owner actions logic. current_user_id_js:', typeof current_user_id_js !== 'undefined' ? current_user_id_js : 'NOT DEFINED');
            // Show/Hide Edit/Delete buttons based on ownership
            const ownerActionsDiv = document.getElementById('modal-owner-actions');
            const editButton = document.getElementById('modal-edit-button');
            
            if (projectDetails.creator_user_id && typeof current_user_id_js !== 'undefined' && parseInt(projectDetails.creator_user_id) === current_user_id_js) {
                console.log('[DEBUG] Showing owner actions. Creator ID:', projectDetails.creator_user_id, 'Current User ID:', current_user_id_js);
                ownerActionsDiv.style.display = 'block';
                editButton.href = `edit_project_idea.php?project_id=${projectDetails.project_id}`;
            } else {
                console.log('[DEBUG] Hiding owner actions. Creator ID:', projectDetails.creator_user_id, 'Current User ID:', typeof current_user_id_js !== 'undefined' ? current_user_id_js : 'NOT DEFINED', 'Comparison failed or IDs do not match.');
                ownerActionsDiv.style.display = 'none';
            }
            console.log('[DEBUG] After owner actions logic.');
            
            console.log('[DEBUG] Attempting to display modal.');
            modal.style.display = 'block';
            mainContentForModal.classList.add('blurred');
            bodyElement.classList.add('modal-open');

            fetchComments(projectDetails.project_id);

            // Add event listener for the new delete button (if not already added)
            // We should ensure this listener is only added once or is idempotent.
            // A simple way is to remove any existing listener before adding.
            const deleteButton = document.getElementById('modal-delete-button');
            const newDeleteButton = deleteButton.cloneNode(true); // Create a new node to remove old listeners
            deleteButton.parentNode.replaceChild(newDeleteButton, deleteButton);

            newDeleteButton.addEventListener('click', function() {
                const projectIdToDelete = projectDetails.project_id;
                if (confirm('Are you sure you want to delete this project idea? This action cannot be undone.')) {
                    fetch('delete_project_idea.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `project_id=${projectIdToDelete}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Close the modal
                            modal.style.display = 'none';
                            mainContentForModal.classList.remove('blurred');
                            bodyElement.classList.remove('modal-open');

                            // Remove the project card from the dashboard
                            const projectCardToRemove = document.querySelector(`.project-card[data-project-id="${projectIdToDelete}"]`);
                            if (projectCardToRemove) {
                                projectCardToRemove.remove();
                            }
                            // Optionally, show a success message (e.g., alert or a custom notification)
                            // alert(data.message); 
                            // Update total project count if displayed
                            const totalProjectsSpan = document.getElementById('total-projects-count');
                            if (totalProjectsSpan) {
                                const currentCount = parseInt(totalProjectsSpan.textContent) || 0;
                                totalProjectsSpan.textContent = Math.max(0, currentCount - 1);
                            }

                        } else {
                            alert('Failed to delete project: ' + (data.message || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting project:', error);
                        alert('An error occurred while trying to delete the project.');
                    });
                }
            });

            if (focusComment) {
                document.getElementById('modal-comment-text').focus();
            }
        }

        function fetchComments(projectId) {
            const commentsList = document.getElementById('modal-comments-list');
            const commentsCountSpanModal = document.getElementById('modal-comments-count');
            commentsList.innerHTML = '<p>Loading comments...</p>'; // Placeholder

            fetch(`fetch_comments.php?project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    commentsList.innerHTML = ''; // Clear loading message
                    if (data.success && data.comments.length > 0) {
                        data.comments.forEach(comment => {
                            const commentDiv = document.createElement('div');
                            commentDiv.style.marginBottom = '10px';
                            commentDiv.style.paddingBottom = '5px';
                            commentDiv.style.borderBottom = '1px solid #eee';
                            commentDiv.innerHTML = `<strong>${comment.user_fullname || 'User'}</strong> (${new Date(comment.created_at).toLocaleDateString()}):<br>${comment.comment_text}`;
                            commentsList.appendChild(commentDiv);
                        });
                        commentsCountSpanModal.textContent = data.comments.length;
                    } else if (data.success && data.comments.length === 0) {
                        commentsList.innerHTML = '<p>No comments yet. Be the first to comment!</p>';
                        commentsCountSpanModal.textContent = 0;
                    } else {
                        commentsList.innerHTML = '<p>Error loading comments.</p>';
                        commentsCountSpanModal.textContent = 0;
                        console.error('Failed to fetch comments:', data.message);
                    }
                })
                .catch(error => {
                    commentsList.innerHTML = '<p>Error loading comments.</p>';
                    commentsCountSpanModal.textContent = 0;
                    console.error('Error fetching comments:', error);
                });
        }

        document.getElementById('modal-comment-form').addEventListener('submit', function(event) {
            event.preventDefault();
            const projectId = document.getElementById('modal-comment-project-id').value;
            const commentText = document.getElementById('modal-comment-text').value.trim();

            if (!commentText) {
                alert('Comment cannot be empty.');
                return;
            }

            fetch('handle_comment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `project_id=${projectId}&comment_text=${encodeURIComponent(commentText)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('modal-comment-text').value = ''; // Clear textarea
                    fetchComments(projectId); // Refresh comments list and count
                    // Update the comment count on the project card outside the modal
                    const projectCardCommentCountSpan = document.querySelector(`.project-card[data-project-id="${projectId}"] .comments-count`);
                    if(projectCardCommentCountSpan){
                        projectCardCommentCountSpan.textContent = data.comments_count;
                    }
                } else {
                    alert('Failed to post comment: ' + data.message);
                    console.error('Failed to post comment:', data.message);
                }
            })
            .catch(error => {
                alert('Error posting comment.');
                console.error('Error posting comment:', error);
            });
        });

        closeButton.onclick = function() {
            modal.style.display = 'none';
            mainContentForModal.classList.remove('blurred');
            bodyElement.classList.remove('modal-open');
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
                mainContentForModal.classList.remove('blurred');
                bodyElement.classList.remove('modal-open');
            }
        }

        // Like functionality
        document.querySelectorAll('.like-action').forEach(likeButton => {
            likeButton.addEventListener('click', function(event) {
                event.stopPropagation(); // Prevent project card click event when liking

                const projectId = this.dataset.projectId;
                const likeIcon = this.querySelector('img');
                const likesCountSpan = this.querySelector('.likes-count');

                if (!likeIcon || !likesCountSpan) {
                    console.error('Like icon or count span not found for project:', projectId);
                    return;
                }

                const originalCount = parseInt(likesCountSpan.textContent);

                if (likeIcon.src.includes('not_liked.png')) {
                    // Şu an beğenilmemiş, BEĞENMEK İSTENİYOR
                    // 1. İyimser UI Güncellemesi
                    likeIcon.src = 'images/liked.png';
                    likesCountSpan.textContent = originalCount + 1;
                    console.log(`Project ${projectId}: Attempting to LIKE. Optimistic UI: liked, count ${originalCount + 1}`);

                    fetch('handle_like.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `project_id=${projectId}&action=like`
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => { 
                                throw new Error(`HTTP error! Status: ${response.status}, Server message: ${text}`); 
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log(`Project ${projectId}: LIKE response data:`, data);
                        if (data.success) {
                            likesCountSpan.textContent = data.likes_count;
                            if (data.user_has_liked) {
                                likeIcon.src = 'images/liked.png';
                            } else {
                                likeIcon.src = 'images/not_liked.png'; 
                                console.warn(`Project ${projectId}: Server reported success for LIKE, but user_has_liked is false. Icon reverted.`);
                            }
                        } else {
                            console.error(`Project ${projectId}: Failed to LIKE (server error):`, data.message);
                            likeIcon.src = 'images/not_liked.png';
                            likesCountSpan.textContent = originalCount;
                        }
                    })
                    .catch(error => {
                        console.error(`Project ${projectId}: Error processing LIKE (fetch catch):`, error);
                        likeIcon.src = 'images/not_liked.png';
                        likesCountSpan.textContent = originalCount;
                    });

                } else if (likeIcon.src.includes('liked.png')) {
                    // Şu an beğenilmiş, BEĞENMEKTEN VAZGEÇİLİYOR (Artık veritabanı işlemi ile)
                    // 1. İyimser UI Güncellemesi
                    likeIcon.src = 'images/not_liked.png';
                    const newOptimisticCount = Math.max(0, originalCount - 1);
                    likesCountSpan.textContent = newOptimisticCount;
                    console.log(`Project ${projectId}: Attempting to UNLIKE. Optimistic UI: not_liked, count ${newOptimisticCount}`);

                    // 2. Sunucuya fetch isteği gönder
                    fetch('handle_like.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `project_id=${projectId}&action=unlike` // Eylem 'unlike' olarak değiştirildi
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => { 
                                throw new Error(`HTTP error! Status: ${response.status}, Server message: ${text}`); 
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log(`Project ${projectId}: UNLIKE response data:`, data);
                        if (data.success) {
                            // Sunucudan gelen doğru sayıyı ve durumu yansıt
                            likesCountSpan.textContent = data.likes_count;
                            if (data.user_has_liked) {
                                // Bu durum, 'unlike' işlemi başarılı ama sunucu 'user_has_liked = true' derse olur (beklenmedik)
                                likeIcon.src = 'images/liked.png'; 
                                console.warn(`Project ${projectId}: Server reported success for UNLIKE, but user_has_liked is true. Icon reverted.`);
                            } else {
                                likeIcon.src = 'images/not_liked.png';
                            }
                        } else {
                            // Sunucu başarısızlık bildirdi (data.success === false)
                            console.error(`Project ${projectId}: Failed to UNLIKE (server error):`, data.message);
                            // İyimser güncellemeyi geri al
                            likeIcon.src = 'images/liked.png'; // Vazgeçme işlemi başarısız olduğu için eski haline (beğenilmiş)
                            likesCountSpan.textContent = originalCount;
                        }
                    })
                    .catch(error => {
                        console.error(`Project ${projectId}: Error processing UNLIKE (fetch catch):`, error);
                        // Ağ hatası veya başka bir JS hatası durumunda iyimser güncellemeyi geri al
                        likeIcon.src = 'images/liked.png'; // Vazgeçme işlemi başarısız olduğu için eski haline (beğenilmiş)
                        likesCountSpan.textContent = originalCount;
                    });
                } else {
                    console.warn(`Project ${projectId}: Unknown like icon src: ${likeIcon.src}. Defaulting to not_liked.`);
                    likeIcon.src = 'images/not_liked.png';
                }
            });
        });

        // Modal içindeki Likes linkine tıklama olayı
        const modalLikesLink = document.getElementById('modal-likes-link');
        const modalLikesList = document.getElementById('modal-likes-list');

        modalLikesLink.addEventListener('click', function(event) {
            event.preventDefault();
            const projectId = document.getElementById('modal-comment-project-id').value; // Proje ID'sini al

            if (modalLikesList.style.display === 'none') {
                modalLikesList.innerHTML = '<p>Loading likers...</p>';
                modalLikesList.style.display = 'block';

                fetch(`fetch_project_likers.php?project_id=${projectId}`)
                    .then(response => response.json())
                    .then(data => {
                        modalLikesList.innerHTML = ''; // Temizle
                        if (data.success && data.likers.length > 0) {
                            const ul = document.createElement('ul');
                            ul.style.listStyleType = 'none';
                            ul.style.paddingLeft = '0';
                            data.likers.forEach(liker => {
                                const li = document.createElement('li');
                                li.textContent = liker.fullname;
                                li.style.padding = '2px 0';
                                ul.appendChild(li);
                            });
                            modalLikesList.appendChild(ul);
                        } else if (data.success && data.likers.length === 0) {
                            modalLikesList.innerHTML = '<p>No likes yet.</p>';
                        } else {
                            modalLikesList.innerHTML = '<p>Error loading likers.</p>';
                            console.error('Failed to fetch likers:', data.message);
                        }
                    })
                    .catch(error => {
                        modalLikesList.innerHTML = '<p>Error loading likers.</p>';
                        console.error('Error fetching likers:', error);
                        modalLikesList.style.display = 'block'; // Hata olsa bile açık kalsın ki mesaj görünsün
                    });
            } else {
                modalLikesList.style.display = 'none';
                modalLikesList.innerHTML = ''; // Gizlerken içeriği temizle
            }
        });

        // Filter Panel Toggle
        const filterIcon = document.getElementById('filter-icon');
        const filterPanel = document.getElementById('filter-panel');

        if (filterIcon && filterPanel) {
            filterIcon.addEventListener('click', function(event) {
                event.stopPropagation(); // Prevent body click from closing it immediately
                filterPanel.classList.toggle('open');
            });

            // Close filter panel if clicked outside
            document.addEventListener('click', function(event) {
                if (filterPanel.classList.contains('open') && !filterPanel.contains(event.target) && event.target !== filterIcon && !filterIcon.contains(event.target)) {
                    filterPanel.classList.remove('open');
                }
            });
            
            // Prevent filter panel from closing when clicking inside it
            filterPanel.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }

        // Preserve filter values on page load
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const sortBy = urlParams.get('sort_by');
            const types = urlParams.getAll('project_types[]');

            if (sortBy) {
                const sortBySelect = document.getElementById('sort_by');
                if (sortBySelect) sortBySelect.value = sortBy;
            }
            const onlyMyIdeas = urlParams.get('only_my_ideas');
            if (onlyMyIdeas === '1') {
                const onlyMyIdeasCheckbox = document.getElementById('only_my_ideas_filter');
                if (onlyMyIdeasCheckbox) onlyMyIdeasCheckbox.checked = true;
            }
            // Repopulation of multi-select for project_types is now handled by PHP embedded in the HTML options directly.
            // This ensures that even if JavaScript is disabled or fails, the selected state is preserved on reload.

            const applyFiltersButton = document.getElementById('apply-filters-btn');
            const filterForm = document.getElementById('filter-form');

            if (applyFiltersButton && filterForm) {
                applyFiltersButton.addEventListener('click', function() {
                    const formData = new FormData(filterForm);
                    const params = new URLSearchParams();

                    // Append sort_by if it has a value
                    const sortByValue = formData.get('sort_by');
                    if (sortByValue) {
                        params.append('sort_by', sortByValue);
                    }

                    // Append only_my_ideas if checked
                    const onlyMyIdeasCheckbox = document.getElementById('only_my_ideas_filter');
                    if (onlyMyIdeasCheckbox && onlyMyIdeasCheckbox.checked) {
                        params.append('only_my_ideas', '1');
                    }

                    // Append all selected project_types
                    const projectTypesValues = formData.getAll('project_types[]');
                    projectTypesValues.forEach(typeId => {
                        if (typeId) { // Ensure not empty value if any
                           params.append('project_types[]', typeId);
                        }
                    });

                    window.location.href = 'dashboard.php?' + params.toString();
                });
            }
        });

        // Ensure this is defined, preferably in the <head> or early in <body>
        const current_user_id_js = <?php echo json_encode($_SESSION['user_id'] ?? 0); ?>;
    </script>
</body>
</html> 