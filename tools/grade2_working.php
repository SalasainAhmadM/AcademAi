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

                // Set redirect ID from first result
                if ($redirectQuizId === null) {
                    $redirectQuizId = $quiz_id;
                }

                echo "<h4>Result Data</h4><pre>";
                var_dump([
                    'answer_id' => $answer['answer_id'],
                    'question_id' => $question_id,
                    'quiz_id' => $quiz_id
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

                // Prepare evaluation data
                $evaluationData = json_encode([
                    'evaluation' => $evaluationResult,
                    'ai_detection' => $aiResult,
                    'plagiarism' => $plagiarismResult,
                    'plagiarism_sources' => $plagiarismSources
                ]);

                // Check for existing evaluation
                $checkStmt = $conn->prepare("SELECT evaluation_id FROM essay_evaluations WHERE answer_id = ?");
                $checkStmt->execute([$answer['answer_id']]);
                $existingEval = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingEval) {
                    $updateStmt = $conn->prepare("
                        UPDATE essay_evaluations 
                        SET overall_score = ?, ai_probability = ?, human_probability = ?, 
                            plagiarism_score = ?, plagiarism_sources = ?, 
                            evaluation_data = ?, evaluation_date = NOW(), quiz_id = ?
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
                        $answer['answer_id']
                    ]);
                } else {
                    $insertStmt = $conn->prepare("
                        INSERT INTO essay_evaluations 
                        (answer_id, student_id, question_id, quiz_id, overall_score, ai_probability, 
                         human_probability, plagiarism_score, plagiarism_sources, evaluation_data, 
                         evaluation_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
                        $evaluationData
                    ]);
                }

                echo "<h4>Database Operation Result</h4><pre>";
                var_dump($success);
                echo "</pre>";
            }
        } catch (PDOException $e) {
            echo "<h3>Database Error</h3><pre>" . $e->getMessage() . "</pre>";
        }

        // Redirect after processing all results
        // if ($redirectQuizId) {
        //     header("Location: ../user/AcademAI-user(learners)-view-quiz-answer-1.php?quiz_id=$redirectQuizId");
        //     exit;
        // }
        $_SESSION['show_success_alert'] = true;

        header("Location: ../user/AcademAI-user(learners)-view-quiz-answer-1.php?quiz_id=$redirectQuizId");
        exit;
    } else {
        echo "<h3 style='color: red;'>No data found in session.</h3>";
    }
} else {
    echo "<h3>Invalid request method.</h3>";
}