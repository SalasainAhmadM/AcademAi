<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../classes/connection.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Essay Evaluation Results</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .success-box {
            background: #e8f5e8;
            padding: 20px;
            margin: 20px 0;
            border: 2px solid #4caf50;
            border-radius: 8px;
            text-align: center;
        }
        .success-box h3 {
            color: #2e7d32;
            margin: 0 0 10px 0;
        }
        .redirect-button {
            background-color: #4caf50;
            color: white;
            padding: 12px 30px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 15px;
            transition: background-color 0.3s;
        }
        .redirect-button:hover {
            background-color: #45a049;
        }
        .debug-section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .debug-section h3 {
            color: #333;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }
        .debug-section h4 {
            color: #555;
            margin-top: 15px;
            background: #f0f0f0;
            padding: 8px 12px;
            border-left: 4px solid #2196F3;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
        }
        hr {
            border: none;
            border-top: 2px solid #ddd;
            margin: 30px 0;
        }
        .error-box {
            background: #ffebee;
            padding: 20px;
            margin: 20px 0;
            border: 2px solid #f44336;
            border-radius: 8px;
        }
        .error-box h3 {
            color: #c62828;
            margin: 0 0 10px 0;
        }
        .warning {
            color: #ff9800;
        }
        .success-text {
            color: #4caf50;
            font-weight: bold;
        }
        .comparison-box {
            background: #e3f2fd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #2196F3;
        }
        .criteria-item {
            background: #fff;
            padding: 12px;
            margin: 8px 0;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .criteria-name {
            font-weight: bold;
            color: #1976d2;
            margin-bottom: 5px;
        }
        .score-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin: 5px 5px 5px 0;
        }
        .score-excellent {
            background: #4caf50;
            color: white;
        }
        .score-good {
            background: #8bc34a;
            color: white;
        }
        .score-satisfactory {
            background: #ffc107;
            color: #333;
        }
        .score-needs-improvement {
            background: #ff9800;
            color: white;
        }
        .score-poor {
            background: #f44336;
            color: white;
        }
        .list-section {
            margin: 8px 0;
        }
        .list-section strong {
            color: #555;
        }
        .list-section ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        .list-section li {
            margin: 3px 0;
        }
    </style>
</head>
<body>";

    echo "<h2>Essay Evaluation Debug Results</h2>";

    if (isset($_SESSION['saveResults']) && is_array($_SESSION['saveResults'])) {
        $allResults = $_SESSION['saveResults'];
        unset($_SESSION['saveResults']);

        $redirectQuizId = null;
        $db = new Database();
        $successCount = 0;
        $errorCount = 0;

        try {
            $conn = $db->connect();
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            foreach ($allResults as $index => $result) {
                echo "<div class='debug-section'>";
                echo "<h3>Processing Result #" . ($index + 1) . "</h3>";

                $answer = $result['answer'] ?? [];
                $evaluationResult = $result['evaluation'] ?? [];
                $aiResult = $result['ai'] ?? [];
                $plagiarismResult = $result['plagiarism'] ?? [];
                $essayText = $result['essay'] ?? '';
                $quiz_id = $result['quiz_id'] ?? 0;
                $question_id = $result['question_id'] ?? 0;
                $teacherComment = $result['teacher_comment'] ?? null;
                $comparedAnswer = $result['compared_answer'] ?? null;

                // Set redirect ID from first result
                if ($redirectQuizId === null) {
                    $redirectQuizId = $quiz_id;
                }

                echo "<h4>📋 Basic Result Data</h4><pre>";
                var_dump([
                    'answer_id' => $answer['answer_id'] ?? 'N/A',
                    'student_name' => ($answer['first_name'] ?? '') . ' ' . ($answer['last_name'] ?? ''),
                    'question_id' => $question_id,
                    'quiz_id' => $quiz_id,
                    'has_compared_answer' => !empty($comparedAnswer),
                    'has_evaluation' => !empty($evaluationResult),
                    'has_ai_result' => !empty($aiResult),
                    'has_plagiarism' => !empty($plagiarismResult)
                ]);
                echo "</pre>";

                // Display Student Answer
                echo "<h4>📝 Student Answer</h4>";
                echo "<div style='background: #f9f9f9; padding: 15px; border-left: 4px solid #2196F3; border-radius: 4px; margin: 10px 0;'>";
                echo "<p style='white-space: pre-wrap; line-height: 1.6;'>" . htmlspecialchars($essayText) . "</p>";
                echo "</div>";

                // Display Reference Answer
                if (!empty($result['reference_answer'])) {
                    echo "<h4>📚 Reference Answer (Teacher's Model)</h4>";
                    echo "<div style='background: #fff3e0; padding: 15px; border-left: 4px solid #ff9800; border-radius: 4px; margin: 10px 0;'>";
                    echo "<p style='white-space: pre-wrap; line-height: 1.6;'>" . htmlspecialchars($result['reference_answer']) . "</p>";
                    echo "</div>";
                }

                // Display Teacher Comment/Benchmark
                if (!empty($teacherComment)) {
                    echo "<h4>👨‍🏫 Teacher Comment / Benchmark Analysis</h4>";
                    echo "<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50; border-radius: 4px; margin: 10px 0;'>";

                    $commentData = json_decode($teacherComment, true);
                    if ($commentData && is_array($commentData)) {
                        echo "<pre style='background: white; padding: 10px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap;'>";
                        echo htmlspecialchars(print_r($commentData, true));
                        echo "</pre>";
                    } else {
                        echo "<p style='white-space: pre-wrap;'>" . htmlspecialchars($teacherComment) . "</p>";
                    }
                    echo "</div>";
                }

                // Display Compared Answer Details
                if (!empty($comparedAnswer)) {
                    $comparedData = is_array($comparedAnswer) ? $comparedAnswer : json_decode($comparedAnswer, true);

                    if ($comparedData && is_array($comparedData)) {
                        echo "<h4>📊 Answer Comparison Analysis</h4>";
                        echo "<div class='comparison-box'>";

                        // Overall scores
                        echo "<div style='background: white; padding: 12px; margin-bottom: 10px; border-radius: 4px;'>";
                        echo "<strong>Overall Performance:</strong><br>";
                        echo "Similarity Score: <span class='score-badge score-good'>" .
                            ($comparedData['overall_similarity_score'] ?? 'N/A') . "%</span><br>";
                        echo "Matched Level: <span class='score-badge score-satisfactory'>" .
                            ($comparedData['overall_matched_header'] ?? 'N/A') . "</span>";
                        echo "</div>";

                        // Individual Criteria Analysis
                        if (isset($comparedData['individual_criteria_analysis']) && is_array($comparedData['individual_criteria_analysis'])) {
                            echo "<h5 style='color: #1976d2; margin-top: 15px;'>Individual Criteria Breakdown:</h5>";

                            foreach ($comparedData['individual_criteria_analysis'] as $criterionName => $criterionData) {
                                echo "<div class='criteria-item'>";
                                echo "<div class='criteria-name'>" . htmlspecialchars($criterionName) . "</div>";

                                // Score and level
                                $score = $criterionData['similarity_score'] ?? 0;
                                $level = $criterionData['matched_level'] ?? 'Unknown';

                                $badgeClass = 'score-satisfactory';
                                if ($score >= 90)
                                    $badgeClass = 'score-excellent';
                                elseif ($score >= 80)
                                    $badgeClass = 'score-good';
                                elseif ($score >= 70)
                                    $badgeClass = 'score-satisfactory';
                                elseif ($score >= 60)
                                    $badgeClass = 'score-needs-improvement';
                                else
                                    $badgeClass = 'score-poor';

                                echo "<span class='score-badge {$badgeClass}'>Score: {$score}%</span>";
                                echo "<span class='score-badge score-satisfactory'>Level: {$level}</span>";

                                // Strengths
                                if (!empty($criterionData['criterion_analysis']['strengths'])) {
                                    echo "<div class='list-section'>";
                                    echo "<strong>✓ Strengths:</strong>";
                                    echo "<ul>";
                                    foreach ($criterionData['criterion_analysis']['strengths'] as $strength) {
                                        echo "<li>" . htmlspecialchars($strength) . "</li>";
                                    }
                                    echo "</ul></div>";
                                }

                                // Weaknesses
                                if (!empty($criterionData['criterion_analysis']['weaknesses'])) {
                                    echo "<div class='list-section'>";
                                    echo "<strong>⚠ Weaknesses:</strong>";
                                    echo "<ul>";
                                    foreach ($criterionData['criterion_analysis']['weaknesses'] as $weakness) {
                                        echo "<li>" . htmlspecialchars($weakness) . "</li>";
                                    }
                                    echo "</ul></div>";
                                }

                                // Suggestions
                                if (!empty($criterionData['criterion_analysis']['suggestions'])) {
                                    echo "<div class='list-section'>";
                                    echo "<strong>💡 Suggestions:</strong>";
                                    echo "<ul>";
                                    foreach ($criterionData['criterion_analysis']['suggestions'] as $suggestion) {
                                        echo "<li>" . htmlspecialchars($suggestion) . "</li>";
                                    }
                                    echo "</ul></div>";
                                }

                                echo "</div>"; // End criteria-item
                            }
                        }

                        // Overall Analysis Summary
                        if (isset($comparedData['overall_analysis']) && is_array($comparedData['overall_analysis'])) {
                            echo "<h5 style='color: #1976d2; margin-top: 15px;'>Overall Summary:</h5>";
                            $overall = $comparedData['overall_analysis'];

                            if (!empty($overall['summary'])) {
                                echo "<div style='background: white; padding: 12px; margin: 10px 0; border-radius: 4px;'>";
                                echo "<p><strong>Summary:</strong><br>" . htmlspecialchars($overall['summary']) . "</p>";
                                echo "</div>";
                            }

                            if (!empty($overall['overall_strengths'])) {
                                echo "<div class='list-section' style='background: white; padding: 12px; margin: 5px 0;'>";
                                echo "<strong>Overall Strengths:</strong><ul>";
                                foreach ($overall['overall_strengths'] as $strength) {
                                    echo "<li>" . htmlspecialchars($strength) . "</li>";
                                }
                                echo "</ul></div>";
                            }

                            if (!empty($overall['overall_weaknesses'])) {
                                echo "<div class='list-section' style='background: white; padding: 12px; margin: 5px 0;'>";
                                echo "<strong>Areas for Improvement:</strong><ul>";
                                foreach ($overall['overall_weaknesses'] as $weakness) {
                                    echo "<li>" . htmlspecialchars($weakness) . "</li>";
                                }
                                echo "</ul></div>";
                            }
                        }

                        // Detailed Recommendation
                        if (!empty($comparedData['detailed_recommendation'])) {
                            echo "<div style='background: white; padding: 12px; margin: 10px 0; border-radius: 4px;'>";
                            echo "<strong>Detailed Recommendation:</strong><br>";
                            echo "<p>" . nl2br(htmlspecialchars($comparedData['detailed_recommendation'])) . "</p>";
                            echo "</div>";
                        }

                        echo "</div>"; // End comparison-box
                    } else {
                        echo "<p class='warning'>⚠ Compared answer data exists but could not be decoded</p>";
                    }
                } else {
                    echo "<h4>📊 Answer Comparison Analysis</h4>";
                    echo "<p>No comparison data available for this answer.</p>";
                }

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

                echo "<h4>🤖 AI Detection Results</h4><pre>";
                echo "AI Probability: {$aiProbability}%\n";
                echo "Human Probability: {$humanProbability}%\n";
                echo "</pre>";

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

                echo "<h4>📝 Plagiarism Check</h4><pre>";
                echo "Overall Plagiarism Score: {$plagiarismScore}%\n";
                echo "Sources Found: " . count($plagiarismSources) . "\n";
                echo "</pre>";

                $sourcesJson = json_encode($plagiarismSources);

                // Overall score handling
                $overallScore = 0;
                if (is_array($evaluationResult) && isset($evaluationResult['overall_weighted_score'])) {
                    $overallScore = floatval($evaluationResult['overall_weighted_score']);
                }

                echo "<h4>📈 Overall Evaluation Score</h4><pre>";
                echo "Score: {$overallScore}%\n";
                echo "</pre>";

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
                    if ($comparedData && isset($comparedData['overall_similarity_score']) && isset($comparedData['overall_matched_header'])) {
                        $evaluationDataArray['answer_comparison'] = [
                            'similarity_score' => $comparedData['overall_similarity_score'],
                            'matched_rubric_level' => $comparedData['overall_matched_header'],
                            'has_detailed_comparison' => true
                        ];
                    }
                }

                $evaluationData = json_encode($evaluationDataArray);

                // Prepare compared answer JSON
                $comparedAnswerJson = null;
                if ($comparedAnswer) {
                    if (is_string($comparedAnswer)) {
                        $testJson = json_decode($comparedAnswer, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $comparedAnswerJson = $comparedAnswer;
                        } else {
                            echo "<p class='warning'>⚠ Warning: Invalid compared answer JSON for answer ID {$answer['answer_id']}</p>";
                        }
                    } else {
                        $comparedAnswerJson = json_encode($comparedAnswer);
                    }
                }

                // Check for existing evaluation
                $checkStmt = $conn->prepare("SELECT evaluation_id FROM essay_evaluations WHERE answer_id = ?");
                $checkStmt->execute([$answer['answer_id']]);
                $existingEval = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingEval) {
                    // Update existing evaluation
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
                        $comparedAnswerJson,
                        $answer['answer_id']
                    ]);

                    echo "<h4>💾 Database Update Result</h4><pre>";
                    echo "Updated existing evaluation for answer ID: {$answer['answer_id']}\n";
                    echo "Success: " . ($success ? "<span class='success-text'>Yes</span>" : "<span class='warning'>No</span>") . "\n";
                    echo "Compared Answer Included: " . (!empty($comparedAnswerJson) ? 'Yes' : 'No') . "\n";
                    echo "</pre>";

                    if ($success)
                        $successCount++;
                    else
                        $errorCount++;
                } else {
                    // Insert new evaluation
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
                        $comparedAnswerJson
                    ]);

                    echo "<h4>💾 Database Insert Result</h4><pre>";
                    echo "Inserted new evaluation for answer ID: {$answer['answer_id']}\n";
                    echo "Success: " . ($success ? "<span class='success-text'>Yes</span>" : "<span class='warning'>No</span>") . "\n";
                    echo "Compared Answer Included: " . (!empty($comparedAnswerJson) ? 'Yes' : 'No') . "\n";
                    echo "</pre>";

                    if ($success)
                        $successCount++;
                    else
                        $errorCount++;
                }

                // Log any database errors
                if (!$success) {
                    echo "<div class='error-box'>";
                    echo "<h4>Database Error</h4><pre>";
                    print_r($conn->errorInfo());
                    echo "</pre>";
                    echo "</div>";
                }

                echo "</div>";
                echo "<hr>";
            }

            echo "<div class='success-box'>";
            echo "<h3>✓ Evaluation Processing Complete!</h3>";
            echo "<p><strong>Total results processed:</strong> " . count($allResults) . "</p>";
            echo "<p><strong>Successful:</strong> <span class='success-text'>{$successCount}</span> | ";
            echo "<strong>Errors:</strong> " . ($errorCount > 0 ? "<span class='warning'>{$errorCount}</span>" : $errorCount) . "</p>";
            echo "<p>Review the results above, then click the button below to view the quiz answers.</p>";
            echo "<button class='redirect-button' onclick=\"window.location.href='../user/AcademAI-user(learners)-view-quiz-answer-1.php?quiz_id={$redirectQuizId}'\">
                    View Quiz Results
                  </button>";
            echo "</div>";

        } catch (PDOException $e) {
            echo "<div class='error-box'>";
            echo "<h3>Database Error</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div class='error-box'>";
            echo "<h3>General Error</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
            echo "</div>";
        }

        $_SESSION['show_success_alert'] = true;

    } else {
        echo "<div class='error-box'>";
        echo "<h3>No Data Found</h3>";
        echo "<p>No evaluation data found in session. Please go back and run the evaluation process again.</p>";
        echo "<p><a href='javascript:history.back()'>← Go Back</a></p>";
        echo "</div>";
    }

    echo "</body></html>";
} else {
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>Invalid Request</title>
</head>
<body>
    <h3>Invalid Request Method</h3>
    <p>This page should be accessed via GET request.</p>
    <p><a href='javascript:history.back()'>← Go Back</a></p>
</body>
</html>";
}
?>