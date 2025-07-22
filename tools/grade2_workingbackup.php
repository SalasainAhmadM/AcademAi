<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../classes/connection.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "<h2>Debug Start</h2>";

    if (isset($_SESSION['saveResults']) && is_array($_SESSION['saveResults'])) {
        $allResults = $_SESSION['saveResults'];
        unset($_SESSION['saveResults']);

        $redirectQuizId = null;
        $db = new Database();

        try {
            $conn = $db->connect();
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            foreach ($allResults as $index => $result) {
                echo "<h3>Processing Result #" . ($index + 1) . "</h3>";

                $answer = $result['answer'];
                $evaluationResult = $result['evaluation'];
                $aiResult = $result['ai'];
                $plagiarismResult = $result['plagiarism'];
                $essayText = $result['essay'];
                $quiz_id = $result['quiz_id'];
                $question_id = $result['question_id'];
                $teacherComment = $result['teacher_comment'] ?? null;
                $comparedAnswer = $result['compared_answer'] ?? null; // Get compared answer data

                // Set redirect ID from first result
                if ($redirectQuizId === null) {
                    $redirectQuizId = $quiz_id;
                }

                echo "<h4>Result Data</h4><pre>";
                var_dump([
                    'answer_id' => $answer['answer_id'],
                    'question_id' => $question_id,
                    'quiz_id' => $quiz_id,
                    'has_compared_answer' => !empty($comparedAnswer)
                ]);
                echo "</pre>";

                // AI probability handling
                $aiProbability = 0;
                $humanProbability = 0;

                if (is_array($aiResult)) {
                    if (isset($aiResult['ai_probability'])) {
                        $aiProbability = floatval($aiResult['ai_probability']);
                        $humanProbability = floatval($aiResult['human_probability']);
                    } elseif (isset($aiResult['formatted'])) {
                        preg_match('/AI Generated: ([\d.]+)%/', $aiResult['formatted'], $aiMatches);
                        preg_match('/Human: ([\d.]+)%/', $aiResult['formatted'], $humanMatches);
                        $aiProbability = isset($aiMatches[1]) ? floatval($aiMatches[1]) : 0;
                        $humanProbability = isset($humanMatches[1]) ? floatval($humanMatches[1]) : 0;
                    }
                }

                // Plagiarism handling
                $plagiarismScore = 0;
                $plagiarismSources = [];

                if (is_array($plagiarismResult)) {
                    if (isset($plagiarismResult['overall_percentage'])) {
                        $plagiarismScore = floatval($plagiarismResult['overall_percentage']);
                    }

                    if (isset($plagiarismResult['sources']) && is_array($plagiarismResult['sources'])) {
                        foreach ($plagiarismResult['sources'] as $source) {
                            $plagiarismSources[] = [
                                'url' => $source['link'] ?? '',
                                'title' => $source['title'] ?? '',
                                'similarity' => ($source['max_similarity'] ?? 0) * 100
                            ];
                        }
                    }
                }

                $sourcesJson = json_encode($plagiarismSources);

                // Overall score handling
                $overallScore = 0;
                if (is_array($evaluationResult) && isset($evaluationResult['overall_weighted_score'])) {
                    $overallScore = floatval($evaluationResult['overall_weighted_score']);
                }

                // Prepare evaluation data with compared answer information
                $evaluationDataArray = [
                    'evaluation' => $evaluationResult,
                    'ai_detection' => $aiResult,
                    'plagiarism' => $plagiarismResult,
                    'plagiarism_sources' => $plagiarismSources
                ];

                // Add compared answer summary to evaluation data if available
                if ($comparedAnswer) {
                    $comparedData = is_string($comparedAnswer) ? json_decode($comparedAnswer, true) : $comparedAnswer;
                    if ($comparedData && isset($comparedData['similarity_score']) && isset($comparedData['matched_header'])) {
                        $evaluationDataArray['answer_comparison'] = [
                            'similarity_score' => $comparedData['similarity_score'],
                            'matched_rubric_level' => $comparedData['matched_header'],
                            'has_detailed_comparison' => true
                        ];
                    }
                }

                $evaluationData = json_encode($evaluationDataArray);

                // Prepare compared answer JSON (ensure it's properly formatted)
                $comparedAnswerJson = null;
                if ($comparedAnswer) {
                    if (is_string($comparedAnswer)) {
                        // Validate JSON string
                        $testJson = json_decode($comparedAnswer, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $comparedAnswerJson = $comparedAnswer;
                        } else {
                            echo "<p style='color: orange;'>Warning: Invalid compared answer JSON for answer ID {$answer['answer_id']}</p>";
                        }
                    } else {
                        $comparedAnswerJson = json_encode($comparedAnswer);
                    }
                }

                echo "<h4>Compared Answer Data</h4><pre>";
                if ($comparedAnswerJson) {
                    $displayData = json_decode($comparedAnswerJson, true);
                    echo "Similarity Score: " . ($displayData['similarity_score'] ?? 'N/A') . "%\n";
                    echo "Matched Header: " . ($displayData['matched_header'] ?? 'N/A') . "\n";
                    echo "Has Detailed Analysis: " . (isset($displayData['detailed_analysis']) ? 'Yes' : 'No') . "\n";
                } else {
                    echo "No compared answer data available\n";
                }
                echo "</pre>";

                // Check for existing evaluation
                $checkStmt = $conn->prepare("SELECT evaluation_id FROM essay_evaluations WHERE answer_id = ?");
                $checkStmt->execute([$answer['answer_id']]);
                $existingEval = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingEval) {
                    // Update existing evaluation with compared answer
                    $updateStmt = $conn->prepare("
                        UPDATE essay_evaluations 
                        SET overall_score = ?, ai_probability = ?, human_probability = ?, 
                            plagiarism_score = ?, plagiarism_sources = ?, 
                            evaluation_data = ?, evaluation_date = NOW(), quiz_id = ?, 
                            teacher_comment = ?, compared_answer = ?
                        WHERE answer_id = ?
                    ");
                    $success = $updateStmt->execute([
                        $overallScore,
                        $aiProbability,
                        $humanProbability,
                        $plagiarismScore,
                        $sourcesJson,
                        $evaluationData,
                        $quiz_id,
                        $teacherComment,
                        $comparedAnswerJson, // Add compared answer to update
                        $answer['answer_id']
                    ]);

                    echo "<h4>Database Update Result</h4><pre>";
                    echo "Updated existing evaluation for answer ID: {$answer['answer_id']}\n";
                    echo "Success: " . ($success ? 'Yes' : 'No') . "\n";
                    echo "Compared Answer Included: " . (!empty($comparedAnswerJson) ? 'Yes' : 'No') . "\n";
                    echo "</pre>";
                } else {
                    // Insert new evaluation with compared answer
                    $insertStmt = $conn->prepare("
                        INSERT INTO essay_evaluations 
                        (answer_id, student_id, question_id, quiz_id, overall_score, ai_probability, 
                         human_probability, plagiarism_score, plagiarism_sources, evaluation_data,
                         teacher_comment, compared_answer, evaluation_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $success = $insertStmt->execute([
                        $answer['answer_id'],
                        $answer['quiz_taker_id'],
                        $question_id,
                        $quiz_id,
                        $overallScore,
                        $aiProbability,
                        $humanProbability,
                        $plagiarismScore,
                        $sourcesJson,
                        $evaluationData,
                        $teacherComment,
                        $comparedAnswerJson // Add compared answer to insert
                    ]);

                    echo "<h4>Database Insert Result</h4><pre>";
                    echo "Inserted new evaluation for answer ID: {$answer['answer_id']}\n";
                    echo "Success: " . ($success ? 'Yes' : 'No') . "\n";
                    echo "Compared Answer Included: " . (!empty($comparedAnswerJson) ? 'Yes' : 'No') . "\n";
                    echo "</pre>";
                }

                // Log any database errors
                if (!$success) {
                    echo "<h4 style='color: red;'>Database Error</h4><pre>";
                    print_r($conn->errorInfo());
                    echo "</pre>";
                }

                echo "<hr>";
            }

            echo "<h3 style='color: green;'>Processing Complete</h3>";
            echo "<p>Total results processed: " . count($allResults) . "</p>";

        } catch (PDOException $e) {
            echo "<h3 style='color: red;'>Database Error</h3><pre>" . $e->getMessage() . "</pre>";
        } catch (Exception $e) {
            echo "<h3 style='color: red;'>General Error</h3><pre>" . $e->getMessage() . "</pre>";
        }

        $_SESSION['show_success_alert'] = true;

        // Redirect after a short delay to allow viewing debug info
        echo "<script>";
        echo "console.log('Redirecting to quiz view in 3 seconds...');";
        echo "setTimeout(function() {";
        echo "    window.location.href = '../user/AcademAI-user(learners)-view-quiz-answer-1.php?quiz_id=$redirectQuizId';";
        echo "}, 3000);";
        echo "</script>";

        echo "<div style='background: #e8f5e8; padding: 20px; margin: 20px 0; border: 1px solid #4caf50; border-radius: 5px;'>";
        echo "<h3 style='color: #2e7d32; margin: 0;'>✓ Evaluation Complete!</h3>";
        echo "<p style='margin: 10px 0 0 0;'>You will be redirected automatically in 3 seconds...</p>";
        echo "<p><a href='../user/AcademAI-user(learners)-view-quiz-answer-1.php?quiz_id=$redirectQuizId' style='color: #1976d2;'>Click here to go now</a></p>";
        echo "</div>";

    } else {
        echo "<h3 style='color: red;'>No data found in session.</h3>";
        echo "<p>Please go back and run the evaluation process again.</p>";
        echo "<p><a href='javascript:history.back()'>Go Back</a></p>";
    }
} else {
    echo "<h3>Invalid request method.</h3>";
    echo "<p>This page should be accessed via GET request.</p>";
}

/**
 * Helper function to validate and format JSON data
 */
function validateAndFormatJson($data, $context = 'unknown')
{
    if (empty($data)) {
        return null;
    }

    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON validation failed for $context: " . json_last_error_msg());
            return null;
        }
        return $data;
    }

    if (is_array($data)) {
        $encoded = json_encode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON encoding failed for $context: " . json_last_error_msg());
            return null;
        }
        return $encoded;
    }

    return null;
}
?>