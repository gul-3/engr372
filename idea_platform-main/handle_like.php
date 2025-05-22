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
$action = isset($_POST['action']) ? $_POST['action'] : '';

if (empty($project_id) || !in_array($action, ['like', 'unlike'])) {
    echo json_encode(['success' => false, 'message' => 'Missing project_id or invalid action.']);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    error_log('handle_like.php: Database connection failed.');
    exit;
}

$conn->begin_transaction();

try {
    if ($action === 'like') {
        $sql = "INSERT INTO project_likes (user_id, project_id) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("handle_like.php: Prepare statement failed (like): " . $conn->error);
            throw new Exception('Prepare statement failed (like): ' . $conn->error);
        }
        $stmt->bind_param("ii", $user_id, $project_id);
        if (!$stmt->execute()) {
            error_log("handle_like.php: Execute failed (like) for user_id: {$user_id}, project_id: {$project_id}. MySQL errno: " . $conn->errno . " Error: " . $stmt->error);
            if ($conn->errno === 1062) { // 1062 is MySQL error code for duplicate entry
                error_log("handle_like.php: Duplicate entry (1062) for like - user_id: {$user_id}, project_id: {$project_id}. Treating as non-fatal.");
            } else {
                throw new Exception('Execute failed (like): ' . $stmt->error . ' (Code: ' . $conn->errno . ')');
            }
        }
        $inserted_rows = $stmt->affected_rows;
        error_log("handle_like.php: Like action for user_id: {$user_id}, project_id: {$project_id}. Inserted rows: {$inserted_rows}");
        $stmt->close();
    } elseif ($action === 'unlike') {
        $sql = "DELETE FROM project_likes WHERE user_id = ? AND project_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("handle_like.php: Prepare statement failed (unlike): " . $conn->error);
            throw new Exception('Prepare statement failed (unlike): ' . $conn->error);
        }
        $stmt->bind_param("ii", $user_id, $project_id);
        if (!$stmt->execute()) {
            error_log("handle_like.php: Execute failed (unlike) for user_id: {$user_id}, project_id: {$project_id}. MySQL errno: " . $conn->errno . " Error: " . $stmt->error);
            throw new Exception('Execute failed (unlike): ' . $stmt->error . ' (Code: ' . $conn->errno . ')');
        }
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        error_log("handle_like.php: Unlike action for user_id: {$user_id}, project_id: {$project_id}. Affected rows: {$affected_rows}");
    }

    // Get updated like count and user's like status
    $sql_count = "SELECT COUNT(*) AS likes_count FROM project_likes WHERE project_id = ?";
    $stmt_count = $conn->prepare($sql_count);
    if (!$stmt_count) {
        throw new Exception('Prepare statement failed (count): ' . $conn->error);
    }
    $stmt_count->bind_param("i", $project_id);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $likes_data = $result_count->fetch_assoc();
    $likes_count = $likes_data['likes_count'] ?? 0;
    $stmt_count->close();

    $sql_user_like = "SELECT COUNT(*) > 0 AS user_has_liked FROM project_likes WHERE project_id = ? AND user_id = ?";
    $stmt_user_like = $conn->prepare($sql_user_like);
    if (!$stmt_user_like) {
        throw new Exception('Prepare statement failed (user_like_status): ' . $conn->error);
    }
    $stmt_user_like->bind_param("ii", $project_id, $user_id);
    $stmt_user_like->execute();
    $result_user_like = $stmt_user_like->get_result();
    $user_like_status = $result_user_like->fetch_assoc();
    $user_has_liked = (bool)($user_like_status['user_has_liked'] ?? false);
    $stmt_user_like->close();
    
    $conn->commit();
    echo json_encode(['success' => true, 'likes_count' => $likes_count, 'user_has_liked' => $user_has_liked]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Error in handle_like.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
       // $conn->close(); // Removed for persistent connections or connection managed by db.php lifecycle
    }
}
?> 