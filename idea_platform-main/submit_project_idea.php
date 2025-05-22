<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantınız

// Kullanıcı giriş yapmış mı kontrol et, yapmadıysa login sayfasına yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$creator_user_id = $_SESSION['user_id'];
$upload_error = '';
$db_error = '';

// Hedef yükleme klasörü (bu klasörün sunucuda var olduğundan ve yazılabilir olduğundan emin olun)
$target_dir = "uploads/project_images/";
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true); // Eğer yoksa oluşturmayı dene
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Form verilerini al
    $project_title = isset($_POST['project_title']) ? trim($_POST['project_title']) : '';
    $project_description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $project_type_ids = isset($_POST['project_type']) ? $_POST['project_type'] : []; // Array olmalı
    $project_member_ids = isset($_POST['project_members']) ? $_POST['project_members'] : []; // Array olmalı
    // $idea_department = isset($_POST['idea_department']) ? trim($_POST['idea_department']) : null; // Formda bu alan varsa alınacak

    // Temel doğrulama
    if (empty($project_title)) {
        $db_error = "Project title is required.";
    } elseif (empty($project_type_ids)) {
        $db_error = "At least one project type must be selected.";
    }

    $image_filename = null;
    // Resim yükleme işlemleri (eğer resim seçildiyse)
    if (isset($_FILES["image"]) && $_FILES["image"]["error"] == 0) {
        $target_file = $target_dir . basename($_FILES["image"]["name"]);
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $original_filename = basename($_FILES["image"]["name"]);

        // Benzersiz dosya adı oluştur (örn: timestamp_orijinalad.uzanti)
        $image_filename = time() . "_" . str_replace(" ", "_", $original_filename);
        $new_target_file = $target_dir . $image_filename;

        // Resim olup olmadığını kontrol et
        $check = getimagesize($_FILES["image"]["tmp_name"]);
        if ($check === false) {
            $upload_error = "File is not an image.";
        }

        // Dosya boyutu kontrolü (örn: 5MB limit)
        if ($_FILES["image"]["size"] > 5000000) {
            $upload_error = "Sorry, your file is too large (max 5MB).";
        }

        // İzin verilen dosya formatları
        $allowed_types = ["jpg", "png", "jpeg", "gif"];
        if (!in_array($imageFileType, $allowed_types)) {
            $upload_error = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
        }

        // Hata yoksa resmi yükle
        if (empty($upload_error) && empty($db_error)) {
            if (!move_uploaded_file($_FILES["image"]["tmp_name"], $new_target_file)) {
                $upload_error = "Sorry, there was an error uploading your file.";
            }
        } else {
            $image_filename = null; // Yükleme başarısız olduysa dosya adını null yap
        }
    } elseif (isset($_FILES["image"]) && $_FILES["image"]["error"] != UPLOAD_ERR_NO_FILE && $_FILES["image"]["error"] != 0) {
        // Dosya seçildi ama bir yükleme hatası var (UPLOAD_ERR_NO_FILE hariç)
        $upload_error = "There was an error with the image upload (Error code: " . $_FILES["image"]["error"] . ").";
        $image_filename = null;
    }

    // Veritabanı işlemleri (hata yoksa devam et)
    if (empty($db_error) && empty($upload_error)) {
        $conn->begin_transaction(); // İşlemi başlat
        try {
            // 1. project_ideas tablosuna ana fikri kaydet
            // $idea_department için formunuzda bir alan varsa SQL'e ekleyin ve bind_param'ı güncelleyin
            $sql_idea = "INSERT INTO project_ideas (creator_user_id, project_title, project_description, image_filename) VALUES (?, ?, ?, ?)";
            $stmt_idea = $conn->prepare($sql_idea);
            // Eğer $idea_department kullanılacaksa: $stmt_idea->bind_param("issss", $creator_user_id, $project_title, $project_description, $idea_department, $image_filename);
            $stmt_idea->bind_param("isss", $creator_user_id, $project_title, $project_description, $image_filename);
            
            if (!$stmt_idea->execute()) {
                throw new Exception("Error saving project idea: " . $stmt_idea->error);
            }
            $project_idea_id = $conn->insert_id; // Yeni eklenen fikrin ID'si
            $stmt_idea->close();

            // 2. project_idea_types bağlantı tablosuna kayıt
            if (!empty($project_type_ids) && is_array($project_type_ids)) {
                $sql_idea_type = "INSERT INTO project_idea_types (project_idea_id, project_type_id) VALUES (?, ?)";
                $stmt_idea_type = $conn->prepare($sql_idea_type);
                foreach ($project_type_ids as $type_id) {
                    $stmt_idea_type->bind_param("ii", $project_idea_id, $type_id);
                    if (!$stmt_idea_type->execute()) {
                        throw new Exception("Error saving project type relation: " . $stmt_idea_type->error);
                    }
                }
                $stmt_idea_type->close();
            }

            // 3. project_idea_members bağlantı tablosuna kayıt
            if (!empty($project_member_ids) && is_array($project_member_ids)) {
                $sql_idea_member = "INSERT INTO project_idea_members (project_idea_id, user_id) VALUES (?, ?)";
                $stmt_idea_member = $conn->prepare($sql_idea_member);
                foreach ($project_member_ids as $member_id) {
                    $stmt_idea_member->bind_param("ii", $project_idea_id, $member_id);
                    if (!$stmt_idea_member->execute()) {
                        throw new Exception("Error saving project member relation: " . $stmt_idea_member->error);
                    }
                }
                $stmt_idea_member->close();
            }

            $conn->commit(); // Her şey başarılıysa işlemi onayla
            // Redirect to a success page (e.g., the dashboard or the new project page)
            $_SESSION['success_message'] = "Project idea submitted successfully!";
            // Örneğin: header("Location: view_idea.php?id=" . $project_idea_id . "&status=success");
            header("Location: dashboard.php?status=idea_created_successfully");
            exit;

        } catch (Exception $e) {
            $conn->rollback(); // Hata olursa işlemi geri al
            $db_error = "Database operation failed: " . $e->getMessage();
            error_log($db_error); // Hatayı sunucu loglarına kaydet
        }
    }
} else {
    // POST isteği değilse (doğrudan erişim), ana sayfaya veya login'e yönlendir
    header("Location: new_project_idea.php"); // Ya da login.php
    exit;
}

// Hata mesajlarını göstermek için (eğer yönlendirme yapılmadıysa)
// Bu kısım, genellikle formu tekrar gösterirken hataları göstermek için kullanılır.
// Ancak bu scriptte başarılı/başarısız işlem sonrası direkt yönlendirme yapılıyor.
// Eğer hata olursa ve yönlendirme yapılmazsa, bu hatalar kullanıcıya gösterilebilir.
if (!empty($upload_error) || !empty($db_error)) {
    echo "<h1>Error Submitting Idea</h1>";
    if (!empty($upload_error)) {
        echo "<p style='color:red;'>Upload Error: " . htmlspecialchars($upload_error) . "</p>";
    }
    if (!empty($db_error)) {
        echo "<p style='color:red;'>Database Error: " . htmlspecialchars($db_error) . "</p>";
    }
    echo "<p><a href='new_project_idea.php'>Go back and try again</a></p>";
}

if ($conn) {
    $conn->close();
}
?> 