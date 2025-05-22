<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantısı

header('Content-Type: application/json');

if (!isset($_GET['project_id'])) {
    echo json_encode(['success' => false, 'message' => 'Project ID not provided.', 'likers' => []]);
    exit;
}

$project_id = intval($_GET['project_id']);

if ($project_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Project ID.', 'likers' => []]);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.', 'likers' => []]);
    error_log('fetch_project_likers.php: Database connection failed.');
    exit;
}

$likers = [];
// Önce project_likes tablosundan user_id'leri al, sonra users tablosundan bu user_id'lere ait fullname'leri çek.
$sql = "SELECT u.fullname 
        FROM project_likes pl
        JOIN users u ON pl.user_id = u.id
        WHERE pl.project_id = ?
        ORDER BY u.fullname ASC"; // İsimlere göre sırala (isteğe bağlı)

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $project_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $likers[] = $row; // $row zaten ['fullname' => 'Some Name'] formatında olacak
        }
        echo json_encode(['success' => true, 'likers' => $likers]);
    } else {
        error_log("Error executing fetch_project_likers query: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error fetching likers: ' . $stmt->error, 'likers' => []]);
    }
    $stmt->close();
} else {
    error_log("Error preparing fetch_project_likers query: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Error preparing to fetch likers: ' . $conn->error, 'likers' => []]);
}

// if ($conn) { $conn->close(); } // Bağlantı yönetimi db.php'ye veya script sonuna bırakılabilir.
?> 