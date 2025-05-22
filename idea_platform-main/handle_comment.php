<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantısı

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
$comment_text = isset($_POST['comment_text']) ? trim($_POST['comment_text']) : '';

if (empty($project_id) || empty($comment_text)) {
    echo json_encode(['success' => false, 'message' => 'Project ID or comment text is missing.']);
    exit;
}

if (strlen($comment_text) > 1000) { // Basic length check
    echo json_encode(['success' => false, 'message' => 'Comment is too long (max 1000 characters).']);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    error_log('handle_comment.php: Database connection failed.');
    exit;
}

$conn->begin_transaction();

try {
    $sql = "INSERT INTO project_comments (user_id, project_id, comment_text) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    $stmt->bind_param("iis", $user_id, $project_id, $comment_text);
    
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    $new_comment_id = $conn->insert_id;
    $stmt->close();

    // Get updated comments count for the project
    $sql_count = "SELECT COUNT(*) AS comments_count FROM project_comments WHERE project_id = ?";
    $stmt_count = $conn->prepare($sql_count);
    if (!$stmt_count) {
        throw new Exception('Prepare statement failed (count): ' . $conn->error);
    }
    $stmt_count->bind_param("i", $project_id);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $count_data = $result_count->fetch_assoc();
    $comments_count = $count_data['comments_count'] ?? 0;
    $stmt_count->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Comment posted.', 'comment_id' => $new_comment_id, 'comments_count' => $comments_count]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Error in handle_comment.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
} finally {
    // Connection management
}
?> 