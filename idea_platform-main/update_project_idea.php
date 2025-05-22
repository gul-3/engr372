<?php
session_start();
require_once 'db.php';

// User authentication check
if (!isset($_SESSION['user_id'])) {
    // Not logged in, redirect to login page or show an error
    $_SESSION['error_message'] = "You must be logged in to update a project.";
    header("Location: login.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

$project_image_upload_dir = "uploads/project_images/";

// Check if form was submitted via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $project_title = isset($_POST['project_title']) ? trim($_POST['project_title']) : '';
    $project_description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $selected_type_ids = isset($_POST['project_type']) && is_array($_POST['project_type']) ? $_POST['project_type'] : [];
    // $selected_member_ids = isset($_POST['project_members']) && is_array($_POST['project_members']) ? $_POST['project_members'] : []; // If members are implemented
    $existing_image_filename = isset($_POST['existing_image_filename']) ? trim($_POST['existing_image_filename']) : null;

    $form_error = '';
    $upload_error = '';

    // --- Basic Validation ---
    if (empty($project_id)) {
        $form_error = "Invalid project ID.";
    } elseif (empty($project_title)) {
        $form_error = "Project title is required.";
    } elseif (empty($selected_type_ids)) {
        $form_error = "At least one project type must be selected.";
    }

    // --- Security Check: Verify User Ownership ---
    if (empty($form_error) && $conn) {
        $stmt_check_owner = $conn->prepare("SELECT creator_user_id FROM project_ideas WHERE id = ?");
        $stmt_check_owner->bind_param("i", $project_id);
        $stmt_check_owner->execute();
        $result_check_owner = $stmt_check_owner->get_result();
        if ($result_check_owner->num_rows === 1) {
            $project_owner_data = $result_check_owner->fetch_assoc();
            if ($project_owner_data['creator_user_id'] != $current_user_id) {
                $form_error = "You are not authorized to update this project.";
            }
        } else {
            $form_error = "Project not found or invalid ID for ownership check.";
        }
        $stmt_check_owner->close();
    }

    // --- Image Upload Handling (if a new image is provided) ---
    $new_image_filename_to_save = $existing_image_filename; // Default to existing image

    if (isset($_FILES["new_image"]) && $_FILES["new_image"]["error"] == UPLOAD_ERR_OK) {
        $target_file = $project_image_upload_dir . basename($_FILES["new_image"]["name"]);
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $original_filename = basename($_FILES["new_image"]["name"]);
        $unique_new_image_filename = time() . "_" . str_replace(" ", "_", $original_filename);
        $new_target_path = $project_image_upload_dir . $unique_new_image_filename;

        $check = getimagesize($_FILES["new_image"]["tmp_name"]);
        if ($check === false) {
            $upload_error = "New file is not an image.";
        }
        if ($_FILES["new_image"]["size"] > 5000000) { // 5MB limit
            $upload_error = "Sorry, your new file is too large (max 5MB).";
        }
        $allowed_types = ["jpg", "png", "jpeg", "gif"];
        if (!in_array($imageFileType, $allowed_types)) {
            $upload_error = "Sorry, only JPG, JPEG, PNG & GIF files are allowed for the new image.";
        }

        if (empty($upload_error) && empty($form_error)) {
            if (move_uploaded_file($_FILES["new_image"]["tmp_name"], $new_target_path)) {
                // New image uploaded successfully, set it as the one to save
                $new_image_filename_to_save = $unique_new_image_filename;
                // Delete old image if it existed and is different from a placeholder
                if (!empty($existing_image_filename) && $existing_image_filename != $new_image_filename_to_save) {
                    $old_image_path = $project_image_upload_dir . $existing_image_filename;
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                }
            } else {
                $upload_error = "Sorry, there was an error uploading your new file.";
            }
        }
    } elseif (isset($_FILES["new_image"]) && $_FILES["new_image"]["error"] != UPLOAD_ERR_NO_FILE) {
        $upload_error = "There was an error with the new image upload (Code: " . $_FILES["new_image"]["error"] . ").";
    }

    // --- Database Update (if no validation or upload errors) ---
    if (empty($form_error) && empty($upload_error) && $conn) {
        $conn->begin_transaction();
        try {
            // 1. Update project_ideas table
            $sql_update_idea = "UPDATE project_ideas SET project_title = ?, project_description = ?, image_filename = ? WHERE id = ? AND creator_user_id = ?";
            $stmt_update_idea = $conn->prepare($sql_update_idea);
            $stmt_update_idea->bind_param("sssii", $project_title, $project_description, $new_image_filename_to_save, $project_id, $current_user_id);
            if (!$stmt_update_idea->execute()) {
                throw new Exception("Error updating project idea: " . $stmt_update_idea->error);
            }
            $stmt_update_idea->close();

            // 2. Update project_idea_types (delete old, insert new)
            $sql_delete_types = "DELETE FROM project_idea_types WHERE project_idea_id = ?";
            $stmt_delete_types = $conn->prepare($sql_delete_types);
            $stmt_delete_types->bind_param("i", $project_id);
            if (!$stmt_delete_types->execute()) {
                throw new Exception("Error deleting old project types: " . $stmt_delete_types->error);
            }
            $stmt_delete_types->close();

            if (!empty($selected_type_ids)) {
                $sql_insert_type = "INSERT INTO project_idea_types (project_idea_id, project_type_id) VALUES (?, ?)";
                $stmt_insert_type = $conn->prepare($sql_insert_type);
                foreach ($selected_type_ids as $type_id) {
                    $type_id_int = intval($type_id); // Ensure it's an integer
                    $stmt_insert_type->bind_param("ii", $project_id, $type_id_int);
                    if (!$stmt_insert_type->execute()) {
                        throw new Exception("Error saving project type relation: " . $stmt_insert_type->error);
                    }
                }
                $stmt_insert_type->close();
            }
            
            // TODO: Update project_idea_members similarly if implemented

            $conn->commit();
            $_SESSION['success_message'] = "Project idea updated successfully!";
            header("Location: edit_project_idea.php?project_id=" . $project_id . "&status=success");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Database operation failed: " . $e->getMessage();
            error_log("Update project idea error for project_id {$project_id}: " . $e->getMessage());
            header("Location: edit_project_idea.php?project_id=" . $project_id . "&status=dberror");
            exit;
        }
    } else {
        // Validation or upload error occurred, set session message and redirect back
        $_SESSION['error_message'] = $form_error ?: $upload_error ?: "An unknown error occurred.";
        // To repopulate form, ideally POST data should be stored in session and re-read on edit page
        // For simplicity, just redirecting with error. User will have to re-input some data if not title/desc which are from DB.
        header("Location: edit_project_idea.php?project_id=" . $project_id . "&status=validationerror");
        exit;
    }
} else {
    // Not a POST request, redirect or show error
    $_SESSION['error_message'] = "Invalid request method.";
    header("Location: dashboard.php");
    exit;
}

if ($conn) {
    $conn->close();
}
?> 