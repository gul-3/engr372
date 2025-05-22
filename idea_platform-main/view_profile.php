<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantısı

// Görüntülenecek kullanıcının ID'sini GET parametresinden al
if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    // Eğer user_id yoksa veya geçerli değilse dashboard'a yönlendir
    header("Location: dashboard.php");
    exit;
}
$profile_user_id = intval($_GET['user_id']);

// Kendi profilini mi görüntülüyor kontrolü (isteğe bağlı, belki farklı bir başlık vs için)
$is_own_profile = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $profile_user_id);

// Fallback URL için (geri butonu)
$fallback_back_url = 'dashboard.php'; 
$back_url = $fallback_back_url;
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $referer_url_parts = parse_url($_SERVER['HTTP_REFERER']);
    $current_url_parts = parse_url($_SERVER['REQUEST_URI']);
    $referer_path = $referer_url_parts['path'] ?? '';
    $current_path = $current_url_parts['path'] ?? '';
    if (basename($referer_path) != basename($current_path)) {
        $back_url = htmlspecialchars($_SERVER['HTTP_REFERER']);
    } else {
        $back_url = $fallback_back_url;
    }
} else {
    $back_url = $fallback_back_url;
}

$profile_username = "";
$profile_name_surname = ""; 
$profile_email = "";        
$profile_company_school_name = "";
$profile_department = "";   
$profile_pic_path = null; 
$user_found = false;

if (isset($conn)) {
    $stmt = $conn->prepare("SELECT username, fullname, email, company_school_name, department, profile_image_filename FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $profile_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user_data = $result->fetch_assoc()) {
            $user_found = true;
            $profile_username = $user_data['username'];
            $profile_name_surname = $user_data['fullname'];
            $profile_email = $user_data['email'];
            $profile_company_school_name = $user_data['company_school_name'];
            $profile_department = $user_data['department'];
            if (!empty($user_data['profile_image_filename'])) {
                $profile_pic_path = "uploads/profile_pics/" . htmlspecialchars($user_data['profile_image_filename']);
            } else {
                $profile_pic_path = 'images/pp_placeholder.png'; // Varsayılan resim
            }
        }
        $stmt->close();
    } else {
        error_log("View Profile: Failed to prepare statement to fetch user data: " . $conn->error);
    }
} else {
    error_log("View Profile: Database connection variable \$conn is not set.");
}

if (!$user_found) {
    // Kullanıcı bulunamazsa bir mesaj göster ve çık
    // Veya dashboard'a yönlendir
    // echo "User not found."; 
    // header("Location: dashboard.php?error=usernotfound");
    // exit;
    // Şimdilik basit bir hata mesajı ile devam edelim, stil bunu desteklemeli
}

$page_title = $user_found ? htmlspecialchars($profile_name_surname) . "'s Profile" : "View Profile";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f4f4f4; 
            color: #333;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 80px; /* Header için boşluk */
        }
        #app-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
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
            font-size: 1.8em;
            font-weight: bold;
            color: #1c3d5a; /* Koyu mavi tema rengi */
        }
        #app-header .close-button {
            font-size: 2em;
            color: #555;
            text-decoration: none;
            cursor: pointer;
        }
        #app-header .close-button:hover {
            color: #000;
        }
        .profile-view-container {
            background-color: #fff;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            display: flex;
            flex-direction: column;
            align-items: center;
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
            overflow: hidden;
            border: 3px solid #dee2e6;
        }
        .profile-pic-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .info-group {
            width: 100%;
            margin-bottom: 18px;
        }
        .info-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
            color: #555;
            font-size: 0.9em;
        }
        .info-group .info-text {
            padding: 10px;
            border: 1px solid #ddd; /* Daha yumuşak bir sınır */
            border-radius: 4px;
            background-color: #f9f9f9; /* Hafif bir arka plan */
            font-size: 1em;
            color: #333;
            word-wrap: break-word; /* Uzun metinlerin taşmasını engelle */
        }
        .no-user-message {
            text-align: center;
            font-size: 1.2em;
            color: #777;
            margin-top: 50px;
        }
    </style>
</head>
<body>
    <header id="app-header">
        <span class="page-title"><?php echo $page_title; ?></span>
        <a href="<?php echo $back_url; ?>" class="close-button" title="Close">&times;</a>
    </header>

    <?php if ($user_found): ?>
    <div class="profile-view-container">
        <div class="profile-pic-area">
            <div class="profile-pic-container">
                <img src="<?php echo $profile_pic_path ?? 'images/pp_placeholder.png'; ?>" alt="Profile Picture">
            </div>
        </div>

        <div class="info-group">
            <label>Username</label>
            <div class="info-text"><?php echo htmlspecialchars($profile_username); ?></div>
        </div>

        <div class="info-group">
            <label>Name Surname</label>
            <div class="info-text"><?php echo htmlspecialchars($profile_name_surname); ?></div>
        </div>

        <div class="info-group">
            <label>Email</label>
            <div class="info-text"><?php echo htmlspecialchars($profile_email); ?></div>
        </div>

        <div class="info-group">
            <label>Company / School</label>
            <div class="info-text"><?php echo htmlspecialchars($profile_company_school_name ?: 'N/A'); ?></div>
        </div>

        <div class="info-group">
            <label>Department</label>
            <div class="info-text"><?php echo htmlspecialchars($profile_department ?: 'N/A'); ?></div>
        </div>
    </div>
    <?php else: ?>
        <div class="no-user-message">
            <p>User not found or an error occurred.</p>
            <p><a href="dashboard.php">Return to Dashboard</a></p>
        </div>
    <?php endif; ?>

</body>
</html> 