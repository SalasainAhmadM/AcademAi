<?php
session_start();
$creation_id = $_SESSION["creation_id"];

require_once '../classes/connection.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Get JSON data from POST request
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

/**
 * Validates if text appears to be a proper sentence or meaningful content
 * Returns true if content appears valid, false if it's random/nonsensical
 */
function isValidRubricContent($text)
{
    if (empty($text) || !is_string($text)) {
        return false;
    }

    $text = trim($text);

    // Check minimum length
    if (strlen($text) < 3) {
        return false;
    }

    // Check for obviously invalid patterns
    $invalidPatterns = [
        '/^[a-z]{1,5}$/i',                    // Very short random letters (up to 5 chars)
        '/^[0-9]+$/',                         // Only numbers
        '/^[^\w\s]+$/',                       // Only special characters
        '/^(.)\1{3,}/',                       // Repeated characters (aaa, 111, etc.)
        '/^[qwertyuiopasdfghjklzxcvbnm]{5,}$/i', // Keyboard mashing
        '/^(yes|no|test|ok|hello|hi)\s*$/i',  // Single basic words
        '/^[a-z]{3,8}\s*$/i',                 // Single random-looking words
    ];

    foreach ($invalidPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return false;
        }
    }

    // Check for reasonable word structure
    $words = str_word_count($text, 1);
    if (count($words) < 2) {
        return false; // Require at least 2 words for meaningful content
    }

    // Check for fragment-like content (incomplete sentences)
    $fragments = [
        '/^(earth is|sun of|word of|the world|a summer|is the|school of|where is)\s*$/i',
        '/^(what is (the )?[a-z]+\?)\s*$/i',  // Simple "what is X?" questions
    ];

    foreach ($fragments as $pattern) {
        if (preg_match($pattern, $text)) {
            return false;
        }
    }

    // Check for random character combinations that look like words but aren't meaningful
    $randomPatterns = [
        '/^[a-z]{4,6}\s+[a-z]{4,6}$/i',     // Two random-looking words
        '/^(asdas|sdasd|asda|sdas|sadas)\b/i', // Common keyboard mashing patterns
    ];

    foreach ($randomPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return false;
        }
    }

    // Check if most words are reasonable length
    $validWordCount = 0;
    foreach ($words as $word) {
        if (strlen($word) >= 2 && strlen($word) <= 25) {
            $validWordCount++;
        }
    }

    // At least 80% of words should be reasonable length
    if (count($words) > 0 && ($validWordCount / count($words)) < 0.8) {
        return false;
    }

    // For longer content with proper structure, likely valid
    if (strlen($text) >= 30 && count($words) >= 5) {
        return true;
    }

    // For shorter texts, require more validation
    $hasCapitalization = preg_match('/[A-Z]/', $text);
    $hasLowercase = preg_match('/[a-z]/', $text);
    $hasPunctuation = preg_match('/[.!?;,:()]/', $text);
    $hasConjunctions = preg_match('/\b(and|or|but|with|for|of|in|on|at|by|from|to)\b/i', $text);

    // Scoring for shorter content
    $score = 0;
    if ($hasCapitalization)
        $score += 1;
    if ($hasLowercase)
        $score += 1;
    if ($hasPunctuation)
        $score += 2;
    if ($hasConjunctions)
        $score += 2;
    if (count($words) >= 4)
        $score += 1;
    if (strlen($text) >= 15)
        $score += 1;

    // Require higher score for validation
    return $score >= 4;
}

/**
 * Validates the entire rubric data structure
 * Returns 0 for valid rubric, 1 for invalid rubric
 */
function validateRubricData($rubricData)
{
    if (!isset($rubricData['rows']) || !is_array($rubricData['rows'])) {
        return 1; // Invalid structure
    }

    $invalidCellCount = 0;
    $totalCellCount = 0;

    foreach ($rubricData['rows'] as $row) {
        // Check criteria name
        if (!empty($row['criteria'])) {
            $totalCellCount++;
            if (!isValidRubricContent($row['criteria'])) {
                $invalidCellCount++;
            }
        }

        // Check each cell content (excluding weight column)
        if (isset($row['cells']) && is_array($row['cells'])) {
            $cellCount = count($row['cells']);
            // Exclude the last cell if it's likely the weight column (numeric)
            $contentCells = array_slice($row['cells'], 0, $cellCount - 1);

            foreach ($contentCells as $cell) {
                if (!empty(trim($cell))) {
                    $totalCellCount++;
                    if (!isValidRubricContent($cell)) {
                        $invalidCellCount++;
                    }
                }
            }
        }
    }

    // If more than 50% of content appears invalid, mark the whole rubric as invalid
    // Changed from 30% to 50% to be more lenient with valid content
    if ($totalCellCount > 0 && ($invalidCellCount / $totalCellCount) > 0.5) {
        return 1; // Invalid
    }

    return 0; // Valid
}

// Validate rubric data to ensure no cell is empty, null, or blank
if (isset($data['data']['rows'])) {
    foreach ($data['data']['rows'] as $row) {
        if (isset($row['cells']) && is_array($row['cells'])) {
            foreach ($row['cells'] as $cell) {
                if (trim($cell) === '') {
                    echo json_encode(['success' => false, 'message' => 'All rubric cells must be filled']);
                    exit;
                }
            }
        }
    }
}

// Check if data is valid
if ($data === null) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

try {
    // Create database connection
    $db = new Database();
    $conn = $db->connect();

    // Handle different actions
    if (isset($data['action'])) {
        // Save new rubric
        if ($data['action'] === 'save_new') {
            // Validate required fields
            if (empty($data['title']) || empty($data['data'])) {
                echo json_encode(['success' => false, 'message' => 'Title and data are required']);
                exit;
            }

            // Validate rubric content and determine is_valid flag
            $is_valid = validateRubricData($data['data']);

            // Begin transaction
            $conn->beginTransaction();

            try {
                // Calculate number of criteria from rubric data
                $num_criteria = count($data['data']['rows']);

                // First, insert into subjects table
                $stmt = $conn->prepare("INSERT INTO subjects (title, num_criteria, creation_id) VALUES (:title, :num_criteria, :creation_id)");

                $title = $data['title'];

                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':num_criteria', $num_criteria, PDO::PARAM_INT);
                $stmt->bindParam(':creation_id', $creation_id, PDO::PARAM_INT);
                $stmt->execute();

                $subject_id = $conn->lastInsertId();

                // Then, insert into rubrics table with the subject_id and is_valid flag
                $stmt = $conn->prepare("INSERT INTO rubrics (title, description, data, creation_id, subject_id, is_valid) VALUES (:title, :description, :data, :creation_id, :subject_id, :is_valid)");

                $description = $data['description'] ?? '';
                $rubric_data = json_encode($data['data']);

                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':data', $rubric_data);
                $stmt->bindParam(':creation_id', $creation_id);
                $stmt->bindParam(':subject_id', $subject_id, PDO::PARAM_INT);
                $stmt->bindParam(':is_valid', $is_valid, PDO::PARAM_INT);
                $stmt->execute();

                $rubric_id = $conn->lastInsertId();

                // Commit transaction
                $conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Subject and rubric saved successfully',
                    'subject_id' => $subject_id,
                    'rubric_id' => $rubric_id,
                    'is_valid' => $is_valid
                ]);
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollBack();
                throw $e;
            }
        }

        // Update existing rubric
        else if ($data['action'] === 'update') {
            // Validate required fields
            if (empty($data['rubric_id']) || empty($data['data'])) {
                echo json_encode(['success' => false, 'message' => 'Rubric ID and data are required']);
                exit;
            }

            // Validate rubric content and determine is_valid flag
            $is_valid = validateRubricData($data['data']);

            // Begin transaction
            $conn->beginTransaction();

            try {
                $rubric_id = (int) $data['rubric_id'];

                // Get the current rubric to find subject_id, current title, and description
                $stmt = $conn->prepare("SELECT subject_id, title, description FROM rubrics WHERE id = :rubric_id");
                $stmt->bindParam(':rubric_id', $rubric_id, PDO::PARAM_INT);
                $stmt->execute();
                $rubric = $stmt->fetch(PDO::FETCH_ASSOC);

                // Calculate number of criteria from rubric data
                $num_criteria = count($data['data']['rows']);

                // Use the title and description from the input data if provided, otherwise keep existing values
                $title = isset($data['title']) && !empty($data['title']) ? $data['title'] : $rubric['title'];
                $description = isset($data['description']) ? $data['description'] : $rubric['description'];

                if ($rubric && $rubric['subject_id']) {
                    // Update subjects table first
                    $stmt = $conn->prepare("UPDATE subjects SET title = :title, num_criteria = :num_criteria WHERE subject_id = :subject_id");

                    $stmt->bindParam(':title', $title);
                    $stmt->bindParam(':num_criteria', $num_criteria, PDO::PARAM_INT);
                    $stmt->bindParam(':subject_id', $rubric['subject_id'], PDO::PARAM_INT);
                    $stmt->execute();
                }

                // Update rubrics table with is_valid flag
                $stmt = $conn->prepare("UPDATE rubrics SET title = :title, description = :description, data = :data, is_valid = :is_valid, updated_at = CURRENT_TIMESTAMP WHERE id = :rubric_id");

                $rubric_data = json_encode($data['data']);

                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':data', $rubric_data);
                $stmt->bindParam(':is_valid', $is_valid, PDO::PARAM_INT);
                $stmt->bindParam(':rubric_id', $rubric_id, PDO::PARAM_INT);

                $stmt->execute();

                // Commit transaction
                $conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Subject and rubric updated successfully',
                    'is_valid' => $is_valid
                ]);
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollBack();
                throw $e;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No action specified']);
    }
} catch (PDOException $e) {
    error_log("Save rubric error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred: ' . $e->getMessage()]);
}
?>