<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantısı

header('Content-Type: application/json');

if (!isset($_GET['project_id'])) {
    echo json_encode(['success' => false, 'message' => 'Project ID not provided.', 'comments' => []]);
    exit;
}

$project_id = intval($_GET['project_id']);

if ($project_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Project ID.', 'comments' => []]);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.', 'comments' => []]);
    error_log('fetch_comments.php: Database connection failed.');
    exit;
}

$comments = [];
$sql = "SELECT pc.comment_text, pc.created_at, u.fullname AS user_fullname 
        FROM project_comments pc 
        JOIN users u ON pc.user_id = u.id 
        WHERE pc.project_id = ? 
        ORDER BY pc.created_at ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $project_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }
        echo json_encode(['success' => true, 'comments' => $comments]);
    } else {
        error_log("Error executing comments query: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error fetching comments: ' . $stmt->error, 'comments' => []]);
    }
    $stmt->close();
} else {
    error_log("Error preparing comments query: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Error preparing to fetch comments: ' . $conn->error, 'comments' => []]);
}

// Connection is usually managed by db.php lifecycle or a final script part, so not closing here explicitly unless it's the very end.
// if ($conn) { $conn->close(); }
?> 