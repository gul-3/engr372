<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($project_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid project ID.']);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    error_log("delete_project_idea.php: Database connection failed.");
    exit;
}

// Verify ownership
$stmt_check_owner = $conn->prepare("SELECT creator_user_id, image_filename FROM project_ideas WHERE id = ?");
if (!$stmt_check_owner) {
    echo json_encode(['success' => false, 'message' => 'Error preparing ownership check: ' . $conn->error]);
    error_log("delete_project_idea.php: Prepare failed (stmt_check_owner): " . $conn->error);
    exit;
}
$stmt_check_owner->bind_param("i", $project_id);
$stmt_check_owner->execute();
$result_check_owner = $stmt_check_owner->get_result();
if ($project_data = $result_check_owner->fetch_assoc()) {
    if ((int)$project_data['creator_user_id'] !== (int)$current_user_id) {
        echo json_encode(['success' => false, 'message' => 'User is not the owner of this project.']);
        $stmt_check_owner->close();
        exit;
    }
    $image_to_delete = $project_data['image_filename'];
} else {
    echo json_encode(['success' => false, 'message' => 'Project not found.']);
    $stmt_check_owner->close();
    exit;
}
$stmt_check_owner->close();

// Start transaction
$conn->begin_transaction();

try {
    // Delete from project_likes
    $stmt_likes = $conn->prepare("DELETE FROM project_likes WHERE project_id = ?");
    if (!$stmt_likes) throw new Exception("Prepare failed (stmt_likes): " . $conn->error);
    $stmt_likes->bind_param("i", $project_id);
    $stmt_likes->execute();
    $stmt_likes->close();

    // Delete from project_comments
    $stmt_comments = $conn->prepare("DELETE FROM project_comments WHERE project_id = ?");
    if (!$stmt_comments) throw new Exception("Prepare failed (stmt_comments): " . $conn->error);
    $stmt_comments->bind_param("i", $project_id);
    $stmt_comments->execute();
    $stmt_comments->close();

    // Delete from project_idea_types
    $stmt_types = $conn->prepare("DELETE FROM project_idea_types WHERE project_idea_id = ?");
    if (!$stmt_types) throw new Exception("Prepare failed (stmt_types): " . $conn->error);
    $stmt_types->bind_param("i", $project_id);
    $stmt_types->execute();
    $stmt_types->close();

    // Delete from project_ideas
    $stmt_project = $conn->prepare("DELETE FROM project_ideas WHERE id = ?");
    if (!$stmt_project) throw new Exception("Prepare failed (stmt_project): " . $conn->error);
    $stmt_project->bind_param("i", $project_id);
    $stmt_project->execute();
    $deleted_rows = $stmt_project->affected_rows;
    $stmt_project->close();

    if ($deleted_rows > 0) {
        $conn->commit();
        
        // Delete image file after successful commit
        if (!empty($image_to_delete)) {
            $image_path = 'uploads/project_images/' . $image_to_delete;
            if (file_exists($image_path)) {
                if (!unlink($image_path)) {
                    // Log error if image deletion fails, but don't make the overall operation fail
                    error_log("delete_project_idea.php: Failed to delete image file: " . $image_path);
                }
            } else {
                error_log("delete_project_idea.php: Image file not found for deletion: " . $image_path);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Project deleted successfully.']);
    } else {
        // This case should ideally be caught by the initial project existence check.
        // But if it reaches here, it means the project was not deleted for some reason.
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Project not found or already deleted.']);
    }

} catch (Exception $e) {
    $conn->rollback();
    error_log("delete_project_idea.php: Transaction failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred during deletion: ' . $e->getMessage()]);
}

$conn->close();
?> 