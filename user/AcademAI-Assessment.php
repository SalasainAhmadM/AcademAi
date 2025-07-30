<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

require_once('../include/extension_links.php');
include('../classes/connection.php');

// Get parameters from URL
$answer_id = $_GET["answer_id"] ?? null;
$quiz_id = $_GET["quiz_id"] ?? null;
$rubric_id = $_GET['rubric_id'] ?? null;

// Connect to the database
$db = new Database();
$conn = $db->connect();

// Get current user info
$current_user_id = $_SESSION['creation_id'];
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, photo_path FROM academai WHERE creation_id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $full_name = trim($user['first_name'] . ' ' . $user['middle_name'] . ' ' . $user['last_name']);
    $email = $user['email'];
    $photo_path = $user['photo_path'] ? $user['photo_path'] : '../img/default-avatar.jpg';
} else {
    $full_name = "User";
    $email = "user@example.com";
    $photo_path = '../img/default-avatar.jpg';
}

// Check if rubric_id is present
if ($rubric_id) {
    try {
        // Find the subject_id associated with this rubric_id
        $subjectQuery = "SELECT DISTINCT c.subject_id 
                         FROM criteria c
                         INNER JOIN essay_questions eq ON c.subject_id = eq.rubric_id
                         WHERE eq.rubric_id = :rubric_id";

        $subjectStmt = $conn->prepare($subjectQuery);
        $subjectStmt->bindParam(':rubric_id', $rubric_id, PDO::PARAM_INT);
        $subjectStmt->execute();
        $subjectResult = $subjectStmt->fetch(PDO::FETCH_ASSOC);

        if ($subjectResult) {
            $subject_id = $subjectResult['subject_id'];

            // Get criteria related to this subject_id
            $criteriaQuery = "SELECT criteria_name, advanced_text, proficient_text, 
                                     needs_improvement_text, warning_text, weight 
                              FROM criteria 
                              WHERE subject_id = :subject_id";

            $criteriaStmt = $conn->prepare($criteriaQuery);
            $criteriaStmt->bindParam(':subject_id', $subject_id, PDO::PARAM_INT);
            $criteriaStmt->execute();
            $criteria = $criteriaStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $criteria = [];
    }
}

$show_teacher_benchmark = true; // Default to show

if ($answer_id) {
    // Check if we should hide teacher benchmark based on the conditions
    $benchmarkCheckQuery = "
        SELECT eq.answer 
        FROM essay_evaluations ee
        INNER JOIN essay_questions eq ON ee.question_id = eq.essay_id
        WHERE ee.answer_id = :answer_id
        LIMIT 1
    ";

    $benchmarkStmt = $conn->prepare($benchmarkCheckQuery);
    $benchmarkStmt->bindParam(':answer_id', $answer_id, PDO::PARAM_INT);
    $benchmarkStmt->execute();
    $benchmarkResult = $benchmarkStmt->fetch(PDO::FETCH_ASSOC);

    // Hide teacher benchmark if essay_questions.answer is "N/A"
    if ($benchmarkResult && $benchmarkResult['answer'] === 'N/A') {
        $show_teacher_benchmark = false;
    }
}

// Fetch evaluations
$evaluations = [];
$teacher_comment = '';
if ($answer_id) {
    $stmt = $conn->prepare("SELECT * FROM essay_evaluations WHERE answer_id = :answer_id");
    $stmt->bindParam(':answer_id', $answer_id, PDO::PARAM_INT);
    $stmt->execute();
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($evaluations)) {
        $teacher_comment = $evaluations[0]['teacher_comment'] ?? '';
    }
}

// Get quiz details
$quiz_details = [];
if ($quiz_id) {
    $stmt = $conn->prepare("SELECT * FROM `essay_questions` WHERE quiz_id = :quiz_id");
    $stmt->bindParam(':quiz_id', $quiz_id, PDO::PARAM_INT);
    $stmt->execute();
    $quiz_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Process evaluation data
$evaluationData = null;
$parsedEvaluation = null;
$overallScore = null;

if (!empty($evaluations)) {
    foreach ($evaluations as $evaluation) {
        $jsonString = $evaluation["evaluation_data"];
        $data = json_decode($jsonString, true);

        if (isset($data["evaluation"]["evaluation"])) {
            $evaluationJson = str_replace(["```json\n", "\n```"], "", $data["evaluation"]["evaluation"]);
            $parsedEvaluation = json_decode($evaluationJson, true);

            if ($parsedEvaluation) {
                $overallScore = $parsedEvaluation["overall_weighted_score"];
                $generalAssessment = $parsedEvaluation["general_assessment"];
            }
        }
        break;
    }
}

// var_dump($generalAssessment);
// Get question number from URL
$question_number = $_GET['question_number'] ?? 'Unknown';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/assessment.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <title>Assessment</title>
</head>

<body>


    <!-- Header with Back Button and User Profile -->
    <div class="header">
        <a href="<?php echo 'AcademAI-user(learners)-view-quiz-answer-1.php' . ($quiz_id ? '?quiz_id=' . urlencode($quiz_id) : ''); ?>"
            class="back-btn">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <div class="header-right">
            <div class="user-profile">
                <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="User" class="profile-pic"
                    onerror="this.onerror=null; this.src='../img/default-avatar.jpg'">
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                    <span class="user-email"><?php echo htmlspecialchars($email); ?></span>

                </div>
            </div>
        </div>
    </div>
    <!-- Header with Back Button and User Profile -->


    <div class="asessment-1">


        <div class="header-question">
            <div class="question-info-header">
                <div class="question-marker">
                    <h2>
                        Detailed Assessment
                    </h2>

                </div>
                <span class="question-badge">
                    <i class="fas fa-question-circle"></i> Question <?php echo htmlspecialchars($question_number); ?>
                </span>

            </div>
        </div>




        <?php
        $stmt1 = $conn->prepare("SELECT * FROM `essay_questions` WHERE quiz_id = :quiz_id");
        $stmt1->bindParam(':quiz_id', $quiz_id, PDO::PARAM_INT);
        $stmt1->execute();
        $result = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT * FROM essay_evaluations WHERE answer_id = :answer_id");
        $stmt->bindParam(':answer_id', $answer_id, PDO::PARAM_INT);
        $stmt->execute();
        $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialize variables to store evaluation data
        $evaluationData = null;
        $parsedEvaluation = null;
        $overallScore = null;

        if (!empty($evaluations)) {
            foreach ($evaluations as $evaluation) {
                $jsonString = $evaluation["evaluation_data"];
                $data = json_decode($jsonString, true);

                // Extract the JSON string from the evaluation field
                $evaluationJson = str_replace(["```json\n", "\n```"], "", $data["evaluation"]["evaluation"]);

                // Decode the clean JSON string
                $parsedEvaluation = json_decode($evaluationJson, true);

                if ($parsedEvaluation) {
                    $overallScore = $parsedEvaluation["overall_weighted_score"];
                    $generalAssessment = $parsedEvaluation["general_assessment"];
                }

                // We only need one evaluation
                break;
            }
        }
        ?>

        <div class="essay-criteria-setting-container">

            <div class="rubric">
                <div class="rubric-table">
                    <?php
                    if (isset($_GET['rubric_id'])) {
                        $rubric_id = $_GET['rubric_id'];

                        // Connect to the database
                        $db = new Database();
                        $conn = $db->connect();

                        try {
                            // Get the rubric data directly using the rubric_id from the URL
                            $rubricQuery = "SELECT data, id FROM rubrics WHERE subject_id = :rubric_id";
                            //echo $rubric_id;
                            $rubricStmt = $conn->prepare($rubricQuery);
                            $rubricStmt->bindParam(':rubric_id', $rubric_id, PDO::PARAM_INT);
                            $rubricStmt->execute();
                            $rubricData = $rubricStmt->fetch(PDO::FETCH_ASSOC);

                            if ($rubricData) {
                                //$subject_id = $rubricData['subject_id'];
                                $criteriaDatas = json_decode($rubricData['data'], true);

                                if ($criteriaDatas && isset($criteriaDatas['headers']) && isset($criteriaDatas['rows'])):
                                    ?>
                                    <table class="table table-hover">
                                        <thead class="criteria-heading" id="criteria-heading">
                                            <tr>
                                                <th scope="col">Criteria</th>
                                                <?php foreach ($criteriaDatas['headers'] as $header): ?>
                                                    <th scope="col"><?php echo htmlspecialchars($header); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody id="criteria-table-body" class="predefined-criteria">
                                            <?php foreach ($criteriaDatas['rows'] as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['criteria']); ?></td>
                                                    <?php foreach ($row['cells'] as $cell): ?>
                                                        <td><?php echo htmlspecialchars($cell); ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p>Invalid rubric data format.</p>
                                <?php endif;
                            } else {
                                echo "<p>No rubric found with the specified ID.</p>";
                            }
                        } catch (Exception $e) {
                            echo "<p>Error loading rubric: " . htmlspecialchars($e->getMessage()) . "</p>";
                        }
                    } else {
                        echo "<p>No rubric ID specified.</p>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    </div>









    <div class="feedback-container">
        <style>
            /* Navigation Bar Styling */
            .nav-bar {
                display: flex;
                justify-content: flex-start;
                padding: 10px;
                margin-bottom: 20px;
                margin-top: 50px;
                border-bottom: 2px solid #092635;

            }

            .nav-bar a {

                font-size: 1.2em;
                font-family: 'Inter', sans-serif;
                text-decoration: none;
                color: #1b4242 !important;

                cursor: pointer;
                padding: 10px 20px;
                /* Add padding for better click area */
                transition: background-color 0.3s ease;
                /* Smooth transition */
            }

            .nav-bar a:hover {
                color: #5c8374 !important;
            }

            /* Active navigation link style */
            .nav-bar a.active {
                background-color: #092635;
                /* Background color for active link */
                color: white !important;
                /* Text color for active link */
            }

            /* Content Section Styling */
            .content-section {
                display: none;
                /* Initially hide all sections */
                margin-top: 20px;
            }

            /* Show the active section */
            .content-section.active {
                display: block;
            }
        </style>
        </head>

        <body>




            <?php
            // Fetch evaluations with compared_answer
            $evaluations = [];
            $teacher_comment = '';
            $compared_answer_data = null;
            $most_common_level = '';

            if ($answer_id) {
                $stmt = $conn->prepare("SELECT * FROM essay_evaluations WHERE answer_id = :answer_id");
                $stmt->bindParam(':answer_id', $answer_id, PDO::PARAM_INT);
                $stmt->execute();
                $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($evaluations)) {
                    $teacher_comment = $evaluations[0]['teacher_comment'] ?? '';

                    if (!empty($evaluations[0]['compared_answer'])) {
                        $compared_answer_data = json_decode($evaluations[0]['compared_answer'], true);

                        if (isset($compared_answer_data['individual_criteria_analysis'])) {
                            $levels = [];

                            foreach ($compared_answer_data['individual_criteria_analysis'] as $criterion) {
                                if (!empty($criterion['matched_level'])) {
                                    $levels[] = $criterion['matched_level'];
                                }
                            }

                            // Count frequency of each matched_level
                            $counts = array_count_values($levels);
                            arsort($counts); // Sort descending by count
                            $most_common_level = key($counts); // First key is the most common
                        }
                    }
                }
            }

            $headers = [];

            if (isset($_GET['rubric_id'])) {
                $rubric_id = $_GET['rubric_id'];

                $db = new Database();
                $conn = $db->connect();

                $rubricStmt = $conn->prepare("SELECT data FROM rubrics WHERE subject_id = :rubric_id");
                $rubricStmt->bindParam(':rubric_id', $rubric_id, PDO::PARAM_INT);
                $rubricStmt->execute();
                $rubricData = $rubricStmt->fetch(PDO::FETCH_ASSOC);

                if ($rubricData) {
                    $criteriaDatas = json_decode($rubricData['data'], true);
                    if ($criteriaDatas && isset($criteriaDatas['headers'])) {
                        $headers = $criteriaDatas['headers'];

                        // Find index of matched level (add 1 for human-readable count)
                        $matched_index = array_search($most_common_level, $headers);
                        if ($matched_index !== false) {
                            $most_common_index = $matched_index + 1;
                        }

                    }
                }
            }

            ?>


            <div class="asess">



                <!-- Navigation Bar -->
                <div class="nav-bar">
                    <a id="nav-system-assessment" onclick="showSection('system-assessment', this)">System Assessment</a>
                    <a id="nav-ai-report" onclick="showSection('ai-report', this)">AI Report</a>
                    <a id="nav-plagiarism-report" onclick="showSection('plagiarism-report', this)">Plagiarism Report</a>
                </div>

                <!-- System Assessment Section -->
                <div id="system-assessment" class="content-section active">
                    <div class="assessment">
                        <?php if ($parsedEvaluation && isset($parsedEvaluation["criteria_scores"])): ?>
                            <?php foreach ($parsedEvaluation["criteria_scores"] as $criteriaName => $criteriaData): ?>
                                <div class="assessment-details">
                                    <div class="asset">
                                        <div class="assess-title col-2">

                                            <p class="rubrics">
                                                <?php
                                                echo htmlspecialchars($criteriaName) . " -<br> Score: " . htmlspecialchars($criteriaData["score"]) . "%";

                                                // Initialize level and levelNumber
                                                $level = '';
                                                $levelNumber = null;

                                                // Check if compared_answer_data exists and has matched_header
                                                if (isset($compared_answer_data['matched_header']) && !empty($compared_answer_data['matched_header'])) {
                                                    // Use the matched_header from compared_answer_data
                                                    $level = $compared_answer_data['matched_header'];

                                                    // Find the corresponding level number
                                                    if (isset($criteriaDatas['headers']) && is_array($criteriaDatas['headers'])) {
                                                        foreach ($criteriaDatas['headers'] as $index => $header) {
                                                            if (strcasecmp($header, $level) === 0) {
                                                                $levelNumber = $index + 1; // Add 1 to make it human-readable (1-based index)
                                                                break;
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    // Fallback: Use regex to extract the level from the feedback (original logic)
                                                    if (preg_match('/✅\s+Why\s+(\w[\w\s]*\w):/i', $criteriaData["feedback"], $matches)) {
                                                        $level = trim($matches[1]);

                                                        // Find the corresponding level number
                                                        if (isset($criteriaDatas['headers']) && is_array($criteriaDatas['headers'])) {
                                                            foreach ($criteriaDatas['headers'] as $index => $header) {
                                                                if (strcasecmp($header, $level) === 0) {
                                                                    $levelNumber = $index + 1; // Add 1 to make it human-readable (1-based index)
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                // echo "<br>Level: " . htmlspecialchars($level);
                                                // if ($levelNumber !== null) {
                                                //     echo " (" . htmlspecialchars($levelNumber) . ")";
                                                // }
                                                ?>
                                                <br>Level:
                                                <?php
                                                // Find matching criterion for current criteria name
                                                $currentMatchingCriterion = null;
                                                if (isset($compared_answer_data['individual_criteria_analysis']) && is_array($compared_answer_data['individual_criteria_analysis'])) {
                                                    $individualAnalysis = $compared_answer_data['individual_criteria_analysis'];

                                                    // Find matching criterion (case-insensitive and flexible matching)
                                                    foreach ($individualAnalysis as $key => $analysis) {
                                                        // Try exact match first
                                                        if (strcasecmp($key, $criteriaName) === 0) {
                                                            $currentMatchingCriterion = $analysis;
                                                            break;
                                                        }
                                                        // Try partial match (useful for variations in naming)
                                                        if (stripos($key, $criteriaName) !== false || stripos($criteriaName, $key) !== false) {
                                                            $currentMatchingCriterion = $analysis;
                                                            break;
                                                        }
                                                        // Check criterion_name field if it exists
                                                        if (isset($analysis['criterion_name']) && strcasecmp($analysis['criterion_name'], $criteriaName) === 0) {
                                                            $currentMatchingCriterion = $analysis;
                                                            break;
                                                        }
                                                    }
                                                }

                                                if (isset($currentMatchingCriterion['matched_level'])) {
                                                    $matchedLevel = $currentMatchingCriterion['matched_level'];
                                                    echo htmlspecialchars($matchedLevel);

                                                    // Find the index in headers array and add 1 for 1-based indexing
                                                    if (isset($criteriaDatas['headers']) && is_array($criteriaDatas['headers'])) {
                                                        $levelIndex = array_search($matchedLevel, $criteriaDatas['headers']);
                                                        if ($levelIndex !== false) {
                                                            $levelNumber = $levelIndex + 1; // Convert to 1-based index
                                                            echo "(" . htmlspecialchars($levelNumber) . ")";
                                                        }
                                                    }
                                                } else {
                                                    // Fallback: Use regex to extract the level from the feedback (original logic)
                                                    if (preg_match('/✅\s+Why\s+(\w[\w\s]*\w):/i', $criteriaData["feedback"], $matches)) {
                                                        $level = trim($matches[1]);
                                                        echo htmlspecialchars($level);

                                                        // Find the corresponding level number
                                                        if (isset($criteriaDatas['headers']) && is_array($criteriaDatas['headers'])) {
                                                            $levelIndex = array_search($level, $criteriaDatas['headers']);
                                                            if ($levelIndex !== false) {
                                                                $levelNumber = $levelIndex + 1; // Convert to 1-based index
                                                                echo "(" . htmlspecialchars($levelNumber) . ")";
                                                            }
                                                        }
                                                    } else {
                                                        echo "Not Available";
                                                    }
                                                }
                                                ?>
                                        </div>



                                        <div class="assess-feedback col-5">
                                            <p class="rubrics-explanation"><strong>Evaluation:</strong></p>
                                            <?php
                                            // Display detailed compared answer analysis if available
                                            if (isset($compared_answer_data) && !empty($compared_answer_data)) {
                                                echo "<div class='compared-answer-analysis' style='background-color: #f7fafc; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #38a169;'>";
                                                echo "<p style='font-weight: bold; color: #2f855a; margin-bottom: 15px;'>📊 Detailed Analysis:</p>";

                                                // Check if we have individual criteria analysis for this criterion
                                                if (isset($compared_answer_data['individual_criteria_analysis']) && is_array($compared_answer_data['individual_criteria_analysis'])) {
                                                    $individualAnalysis = $compared_answer_data['individual_criteria_analysis'];

                                                    // Find matching criterion (case-insensitive and flexible matching)
                                                    $matchingCriterion = null;
                                                    foreach ($individualAnalysis as $key => $analysis) {
                                                        // Try exact match first
                                                        if (strcasecmp($key, $criteriaName) === 0) {
                                                            $matchingCriterion = $analysis;
                                                            break;
                                                        }
                                                        // Try partial match (useful for variations in naming)
                                                        if (stripos($key, $criteriaName) !== false || stripos($criteriaName, $key) !== false) {
                                                            $matchingCriterion = $analysis;
                                                            break;
                                                        }
                                                        // Check criterion_name field if it exists
                                                        if (isset($analysis['criterion_name']) && strcasecmp($analysis['criterion_name'], $criteriaName) === 0) {
                                                            $matchingCriterion = $analysis;
                                                            break;
                                                        }
                                                    }

                                                    if ($matchingCriterion) {
                                                        // Display performance level and score
                                                        // if (isset($matchingCriterion['similarity_score'])) {
                                                        //     echo "<div style='margin-bottom: 10px;'>";
                                                        //     echo "<strong>📈 Similarity Score:</strong> " . htmlspecialchars($matchingCriterion['similarity_score']) . "%";
                                                        //     echo "</div>";
                                                        // }
                                    
                                                        // if (isset($matchingCriterion['matched_level'])) {
                                                        //     echo "<div style='margin-bottom: 10px;'>";
                                                        //     echo "<strong>🎯 Performance Level:</strong> " . htmlspecialchars($matchingCriterion['matched_level']);
                                                        //     echo "</div>";
                                                        // }
                                    
                                                        // Display performance assessment
                                                        if (isset($matchingCriterion['performance_level'])) {
                                                            echo "<div style='margin-bottom: 15px; padding: 10px; background-color: #edf2f7; border-radius: 6px;'>";
                                                            echo "<strong>📝 Assessment:</strong><br>";
                                                            echo htmlspecialchars($matchingCriterion['performance_level']);
                                                            echo "</div>";
                                                        }

                                                        // Display criterion analysis details
                                                        if (isset($matchingCriterion['criterion_analysis'])) {
                                                            $analysis = $matchingCriterion['criterion_analysis'];

                                                            // Key points covered
                                                            if (isset($analysis['key_points_covered']) && is_array($analysis['key_points_covered']) && !empty($analysis['key_points_covered'])) {
                                                                echo "<div style='margin-bottom: 10px;'>";
                                                                echo "<strong style='color: #38a169;'>✅ Key Points Covered:</strong>";
                                                                echo "<ul style='margin: 5px 0 0 20px; color: #2d3748;'>";
                                                                foreach ($analysis['key_points_covered'] as $point) {
                                                                    if (!empty(trim($point))) {
                                                                        echo "<li>" . htmlspecialchars($point) . "</li>";
                                                                    }
                                                                }
                                                                echo "</ul>";
                                                                echo "</div>";
                                                            }

                                                            // Missing points
                                                            if (isset($analysis['missing_points']) && is_array($analysis['missing_points']) && !empty($analysis['missing_points'])) {
                                                                // Filter out N/A entries
                                                                $validMissingPoints = array_filter($analysis['missing_points'], function ($point) {
                                                                    return !empty(trim($point)) && stripos($point, 'N/A') === false;
                                                                });

                                                                if (!empty($validMissingPoints)) {
                                                                    echo "<div style='margin-bottom: 10px;'>";
                                                                    echo "<strong style='color: #e53e3e;'>❌ Missing Points:</strong>";
                                                                    echo "<ul style='margin: 5px 0 0 20px; color: #2d3748;'>";
                                                                    foreach ($validMissingPoints as $point) {
                                                                        echo "<li>" . htmlspecialchars($point) . "</li>";
                                                                    }
                                                                    echo "</ul>";
                                                                    echo "</div>";
                                                                }
                                                            }

                                                            // Strengths
                                                            if (isset($analysis['strengths']) && is_array($analysis['strengths']) && !empty($analysis['strengths'])) {
                                                                echo "<div style='margin-bottom: 10px;'>";
                                                                echo "<strong style='color: #38a169;'>💪 Strengths:</strong>";
                                                                echo "<ul style='margin: 5px 0 0 20px; color: #2d3748;'>";
                                                                foreach ($analysis['strengths'] as $strength) {
                                                                    if (!empty(trim($strength))) {
                                                                        echo "<li>" . htmlspecialchars($strength) . "</li>";
                                                                    }
                                                                }
                                                                echo "</ul>";
                                                                echo "</div>";
                                                            }

                                                            // Weaknesses
                                                            if (isset($analysis['weaknesses']) && is_array($analysis['weaknesses']) && !empty($analysis['weaknesses'])) {
                                                                // Filter out "None identified" entries
                                                                $validWeaknesses = array_filter($analysis['weaknesses'], function ($weakness) {
                                                                    return !empty(trim($weakness)) && stripos($weakness, 'None identified') === false;
                                                                });

                                                                if (!empty($validWeaknesses)) {
                                                                    echo "<div style='margin-bottom: 10px;'>";
                                                                    echo "<strong style='color: #e53e3e;'>⚠️ Areas to Improve:</strong>";
                                                                    echo "<ul style='margin: 5px 0 0 20px; color: #2d3748;'>";
                                                                    foreach ($validWeaknesses as $weakness) {
                                                                        echo "<li>" . htmlspecialchars($weakness) . "</li>";
                                                                    }
                                                                    echo "</ul>";
                                                                    echo "</div>";
                                                                }
                                                            }
                                                        }

                                                        // Improvement focus
                                                        // if (isset($matchingCriterion['improvement_focus']) && !empty($matchingCriterion['improvement_focus'])) {
                                                        //     echo "<div style='background-color: #fef5e7; padding: 10px; border-radius: 6px; border-left: 3px solid #f6ad55;'>";
                                                        //     echo "<strong style='color: #c05621;'>🎯 Focus for Improvement:</strong><br>";
                                                        //     echo "<span style='color: #2d3748;'>" . htmlspecialchars($matchingCriterion['improvement_focus']) . "</span>";
                                                        //     echo "</div>";
                                                        // }
                                                    }
                                                }

                                                // If no specific criterion match found, show debug info and available criteria
                                                if (!isset($matchingCriterion)) {
                                                    echo "<div style='background-color: #fff3cd; padding: 10px; border-radius: 6px; margin-bottom: 10px; border-left: 3px solid #ffc107;'>";
                                                    echo "<strong style='color: #856404;'>⚠️ Debug Info:</strong><br>";
                                                    echo "<span style='color: #2d3748; font-size: 12px;'>Looking for: '" . htmlspecialchars($criteriaName) . "'<br>";
                                                    echo "Available criteria: ";
                                                    if (isset($compared_answer_data['individual_criteria_analysis'])) {
                                                        $availableCriteria = array_keys($compared_answer_data['individual_criteria_analysis']);
                                                        echo implode(', ', array_map('htmlspecialchars', $availableCriteria));
                                                    } else {
                                                        echo "None found";
                                                    }
                                                    echo "</span>";
                                                    echo "</div>";

                                                    // Display overall analysis as fallback
                                                    if (isset($compared_answer_data['overall_analysis'])) {
                                                        $overallAnalysis = $compared_answer_data['overall_analysis'];

                                                        if (isset($overallAnalysis['average_score'])) {
                                                            echo "<div style='margin-bottom: 10px;'>";
                                                            echo "<strong>📊 Overall Average Score:</strong> " . htmlspecialchars($overallAnalysis['average_score']) . "%";
                                                            echo "</div>";
                                                        }

                                                        if (isset($overallAnalysis['summary'])) {
                                                            echo "<div style='margin-bottom: 10px; padding: 10px; background-color: #edf2f7; border-radius: 6px;'>";
                                                            echo "<strong>📋 Summary:</strong><br>";
                                                            echo htmlspecialchars($overallAnalysis['summary']);
                                                            echo "</div>";
                                                        }
                                                    }
                                                }

                                                echo "</div>";
                                            }
                                            ?>

                                            <?php if (!empty($teacher_comment) && $show_teacher_benchmark): ?>
                                                <div class="teacher-benchmark"
                                                    style="margin-top: 15px; border-top: 1px solid #ccc; padding-top: 10px;">
                                                    <p style="font-weight: bold; color: #1b4242;">📊 Creators Benchmark:</p>
                                                    <?php
                                                    $decodedComment = json_decode($teacher_comment, true);

                                                    if ($decodedComment && isset($decodedComment['rubric_analysis']['criterion_scores'])) {
                                                        // Get the criterion scores from teacher comment
                                                        $criterionScores = $decodedComment['rubric_analysis']['criterion_scores'];

                                                        // Create a simple counter to match criteria by position
                                                        static $criteriaCounter = 0;
                                                        $criterionKey = 'criterion_' . $criteriaCounter;

                                                        // Check if this specific criterion exists in teacher feedback
                                                        if (isset($criterionScores[$criterionKey])) {
                                                            $criterion = $criterionScores[$criterionKey];
                                                            echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px; border-left: 4px solid #1b4242;'>";
                                                            // echo "<p><strong>📊 Teacher Score:</strong> " . htmlspecialchars($criterion['score']) . "</p>";
                                                            echo "<p><strong>💬 Detailed Feedback:</strong> " . htmlspecialchars($criterion['feedback']) . "</p>";
                                                            echo "</div>";
                                                        }

                                                        $criteriaCounter++;

                                                    } elseif (isset($decodedComment['grade_justification'])) {
                                                        // Show overall grade justification
                                                        echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px; border-left: 4px solid #1b4242;'>";
                                                        echo "<p><strong>📝 Grade Justification:</strong> " . htmlspecialchars($decodedComment['grade_justification']) . "</p>";
                                                        echo "</div>";

                                                    } elseif (isset($decodedComment['overall_assessment'])) {
                                                        // Show overall assessment
                                                        echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px; border-left: 4px solid #1b4242;'>";
                                                        echo "<p><strong>🎯 Overall Assessment:</strong> " . htmlspecialchars($decodedComment['overall_assessment']) . "</p>";
                                                        echo "</div>";

                                                    } else {
                                                        // Plain text fallback
                                                        echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px; border-left: 4px solid #1b4242;'>";
                                                        echo "<p>" . nl2br(htmlspecialchars($teacher_comment)) . "</p>";
                                                        echo "</div>";
                                                    }

                                                    // Show additional teacher feedback sections if available
                                                    if ($decodedComment) {
                                                        // Constructive Feedback Section
                                                        if (isset($decodedComment['constructive_feedback'])) {
                                                            echo "<div style='background-color: #e8f5e8; padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #28a745;'>";
                                                            echo "<p style='font-weight: bold; color: #155724; margin-bottom: 10px;'>🎯 Constructive Feedback:</p>";

                                                            if (isset($decodedComment['constructive_feedback']['specific_improvements']) && is_array($decodedComment['constructive_feedback']['specific_improvements'])) {
                                                                echo "<p><strong>Specific Improvements:</strong></p>";
                                                                echo "<ul style='margin-left: 15px;'>";
                                                                foreach ($decodedComment['constructive_feedback']['specific_improvements'] as $improvement) {
                                                                    echo "<li>" . htmlspecialchars($improvement) . "</li>";
                                                                }
                                                                echo "</ul>";
                                                            }

                                                            if (isset($decodedComment['constructive_feedback']['study_recommendations']) && is_array($decodedComment['constructive_feedback']['study_recommendations'])) {
                                                                echo "<p><strong>📚 Study Recommendations:</strong></p>";
                                                                echo "<ul style='margin-left: 15px;'>";
                                                                foreach ($decodedComment['constructive_feedback']['study_recommendations'] as $recommendation) {
                                                                    echo "<li>" . htmlspecialchars($recommendation) . "</li>";
                                                                }
                                                                echo "</ul>";
                                                            }

                                                            if (isset($decodedComment['constructive_feedback']['writing_tips']) && is_array($decodedComment['constructive_feedback']['writing_tips'])) {
                                                                echo "<p><strong>✍️ Writing Tips:</strong></p>";
                                                                echo "<ul style='margin-left: 15px;'>";
                                                                foreach ($decodedComment['constructive_feedback']['writing_tips'] as $tip) {
                                                                    echo "<li>" . htmlspecialchars($tip) . "</li>";
                                                                }
                                                                echo "</ul>";
                                                            }
                                                            echo "</div>";
                                                        }

                                                        // Comparison with Reference Section
                                                        if (isset($decodedComment['comparison_with_reference'])) {
                                                            echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #ffc107;'>";
                                                            echo "<p style='font-weight: bold; color: #856404; margin-bottom: 10px;'>📋 Comparison with Reference:</p>";

                                                            if (isset($decodedComment['comparison_with_reference']['missing_elements']) && is_array($decodedComment['comparison_with_reference']['missing_elements'])) {
                                                                echo "<p><strong>❌ Missing Elements:</strong></p>";
                                                                echo "<ul style='margin-left: 15px;'>";
                                                                foreach ($decodedComment['comparison_with_reference']['missing_elements'] as $element) {
                                                                    echo "<li>" . htmlspecialchars($element) . "</li>";
                                                                }
                                                                echo "</ul>";
                                                            }

                                                            if (isset($decodedComment['comparison_with_reference']['differences']) && is_array($decodedComment['comparison_with_reference']['differences'])) {
                                                                echo "<p><strong>🔄 Key Differences:</strong></p>";
                                                                echo "<ul style='margin-left: 15px;'>";
                                                                foreach ($decodedComment['comparison_with_reference']['differences'] as $difference) {
                                                                    echo "<li>" . htmlspecialchars($difference) . "</li>";
                                                                }
                                                                echo "</ul>";
                                                            }

                                                            if (isset($decodedComment['comparison_with_reference']['similarities']) && is_array($decodedComment['comparison_with_reference']['similarities']) && !empty($decodedComment['comparison_with_reference']['similarities'])) {
                                                                echo "<p><strong>✅ Similarities:</strong></p>";
                                                                echo "<ul style='margin-left: 15px;'>";
                                                                foreach ($decodedComment['comparison_with_reference']['similarities'] as $similarity) {
                                                                    echo "<li>" . htmlspecialchars($similarity) . "</li>";
                                                                }
                                                                echo "</ul>";
                                                            }
                                                            echo "</div>";
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (isset($criteriaData["suggestions"]) && !empty($criteriaData["suggestions"])): ?>
                                            <div class="feedback-suggestion col-5">
                                                <p class="feedback-title" style="color:#1b4242;"><strong>Suggestions for
                                                        Improvement:</strong></p>
                                                <ul class="suggestion-list" style="color:#1b4242;">
                                                    <?php foreach ($criteriaData["suggestions"] as $suggestion): ?>
                                                        <li><?php echo htmlspecialchars($suggestion); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Display general assessment -->
                            <?php if ($generalAssessment): ?>
                                <div class="assessment-details general-assessment">
                                    <div class="asset">
                                        <div class="assess-t col-2">
                                            <p class="rubrics">General Assessment</p>
                                        </div>

                                        <?php if (isset($generalAssessment["strengths"]) && !empty($generalAssessment["strengths"])): ?>
                                            <div class="assess-feedback col-5">
                                                <p class="feedback-title"><strong>📋 General Assessment and Feedback:</strong></p>
                                                <ul class="assessment-list" style="color: #1b4242;">
                                                    <?php foreach ($generalAssessment["strengths"] as $strength): ?>
                                                        <li><?php echo htmlspecialchars($strength); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (isset($generalAssessment["areas_for_improvement"]) && !empty($generalAssessment["areas_for_improvement"])): ?>
                                            <div class="feedback-suggestion col-5 improvements">
                                                <p class="feedback-title" style="color: #1b4242;"><strong>✨ Needs Improvement /
                                                        Suggestions for Improvement:</strong></p>
                                                <ul class="assessment-list" style="color: #1b4242;">
                                                    <?php foreach ($generalAssessment["areas_for_improvement"] as $improvement): ?>
                                                        <li><?php echo htmlspecialchars($improvement); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="assessment-details">
                                <p>No evaluation data found for this answer.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- AI Report Section -->
                <div id="ai-report" class="content-section">
                    <div class="assessment">
                        <?php if (isset($parsedEvaluation["ai_detection"]) && !empty($parsedEvaluation["ai_detection"])): ?>
                            <div class="assessment-details-ai">
                                <p class="rubrics-ai">AI Detection Analysis</p>
                                <div class="ai-score-container">
                                    <div class="ai-score-chart">
                                        <div class="ai-meter">
                                            <?php
                                            $ai_percentage = $parsedEvaluation["ai_detection"]["ai_probability"] ?? 0;
                                            $human_percentage = $parsedEvaluation["ai_detection"]["human_probability"] ?? 0;

                                            // Normalize to 100% bar (ensures total width = 100%)
                                            $total = $ai_percentage + $human_percentage;
                                            $ai_width = $total > 0 ? ($ai_percentage / $total) * 100 : 0;
                                            $human_width = 100 - $ai_width;
                                            ?>

                                            <div class="ai-portion" style="width: <?php echo $ai_width; ?>%;">
                                                <span class="ai-label">AI:
                                                    <?php echo number_format($ai_width, 2); ?>%</span>
                                            </div>
                                            <div class="human-portion" style="width: <?php echo $human_width; ?>%;">
                                                <span class="human-label">Human:
                                                    <?php echo number_format($human_width, 2); ?>%</span>
                                            </div>
                                        </div>

                                        <div class="ai-explanation">
                                            <br>
                                            <h4>Detailed Explanation:</h4>
                                            <?php if (isset($parsedEvaluation["ai_detection"]["explanation"])): ?>
                                                <div class="ai-summary" style="white-space: pre-wrap;">
                                                    <?php
                                                    // Clean up explanation
                                                    $cleaned_explanation = preg_replace([
                                                        '/\*\* ?: ?\*\*/',    // remove ** :** artifacts
                                                        '/```json/',          // remove ```json
                                                        '/```/',              // remove ```
                                                        '/,+/',               // remove trailing commas
                                                        '/\bJSON\b/',         // remove literal word JSON
                                                    ], '', $parsedEvaluation["ai_detection"]["explanation"]);

                                                    echo htmlspecialchars(trim($cleaned_explanation));
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="ai-summary">
                                                    <p>No explanation found.</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="ai-explanation">
                                    <h4>What does this mean?</h4>
                                    <p>This analysis estimates the probability that the text was generated by AI versus
                                        written by a human. A higher AI percentage suggests the content may have been
                                        created or heavily assisted by AI tools like ChatGPT or similar models.</p>

                                    <?php if ($ai_percentage > 70): ?>
                                        <div class="ai-warning">
                                            <p><strong>Note:</strong> This content shows a high probability of AI
                                                generation. If this work was submitted as original human work, please review
                                                your institution's policies on AI-assisted writing.</p>
                                        </div>
                                    <?php elseif ($ai_percentage > 40): ?>
                                        <div class="ai-caution">
                                            <p><strong>Note:</strong> This content shows moderate indicators of AI
                                                assistance. The writing may contain sections created with AI help.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="ai-ok">
                                            <p>This content shows low likelihood of AI authorship. It is likely to be
                                                human-written.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="assessment-details-ai">
                                <p class="rubrics-ai">AI Detection Analysis</p>
                                <p class="ai-unavailable">AI analysis data is not available for this submission.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>


                <?php
                // Initialize plagiarism data - IMPROVED VERSION
                $plagiarismData = null;
                $plagiarismSources = [];
                $plagiarismScore = 0;
                $aiPlagiarismData = null; // For AI detection plagiarism data
                
                // Extract plagiarism data from the main evaluation data (top level)
                if (!empty($evaluations)) {
                    foreach ($evaluations as $evaluation) {
                        $jsonString = $evaluation["evaluation_data"];
                        $data = json_decode($jsonString, true);

                        // Check for plagiarism data at the top level (main plagiarism analysis)
                        if (isset($data["plagiarism"]) && !empty($data["plagiarism"])) {
                            $plagiarismData = $data["plagiarism"];
                            $plagiarismScore = $plagiarismData["plagiarism_score"] ?? 0;

                            // Get sources from top-level plagiarism data
                            if (isset($data["plagiarism"]["sources"]) && !empty($data["plagiarism"]["sources"])) {
                                $plagiarismSources = $data["plagiarism"]["sources"];
                            }

                            // Also check for plagiarism_sources at the same level
                            if (isset($data["plagiarism_sources"]) && !empty($data["plagiarism_sources"])) {
                                $additionalSources = $data["plagiarism_sources"];
                                // Merge with existing sources if any
                                $plagiarismSources = array_merge($plagiarismSources, $additionalSources);
                            }
                        }

                        // Also check for AI evaluation plagiarism data (backup)
                        if (isset($data["evaluation"]["evaluation"])) {
                            $evaluationJson = str_replace(["```json\n", "\n```"], "", $data["evaluation"]["evaluation"]);
                            $parsedEvaluation = json_decode($evaluationJson, true);

                            if ($parsedEvaluation && isset($parsedEvaluation["plagiarism"])) {
                                $aiPlagiarismData = $parsedEvaluation["plagiarism"];

                                // If we don't have main plagiarism data, use AI evaluation data as fallback
                                if (!$plagiarismData && $aiPlagiarismData) {
                                    $plagiarismData = $aiPlagiarismData;
                                    $plagiarismScore = $aiPlagiarismData["overall_percentage"] ?? ($aiPlagiarismData["overall_score"] * 100 ?? 0);
                                }

                                // Add AI evaluation sources if available
                                if (isset($parsedEvaluation["plagiarism_sources"]) && !empty($parsedEvaluation["plagiarism_sources"])) {
                                    $aiSources = $parsedEvaluation["plagiarism_sources"];
                                    $plagiarismSources = array_merge($plagiarismSources, $aiSources);
                                }
                            }
                        }

                        break; // We only need one evaluation
                    }
                }

                // Remove duplicate sources based on URL
                $uniqueSources = [];
                $seenUrls = [];
                foreach ($plagiarismSources as $source) {
                    $url = is_array($source['url'] ?? '') ? implode('', $source['url']) : ($source['url'] ?? '');
                    if (!in_array($url, $seenUrls) && !empty($url)) {
                        $uniqueSources[] = $source;
                        $seenUrls[] = $url;
                    }
                }
                $plagiarismSources = $uniqueSources;

                // Determine the assessment level
                $assessment = '';
                $description = '';
                $color = 'green';
                $isClean = false;

                if (isset($plagiarismData["assessment"])) {
                    $assessment = $plagiarismData["assessment"];
                    $description = $plagiarismData["description"] ?? '';
                    $color = $plagiarismData["color"] ?? 'green';
                    $isClean = (strtoupper($assessment) === 'CLEAN');
                } elseif ($plagiarismScore > 0) {
                    if ($plagiarismScore >= 70) {
                        $assessment = 'HIGH';
                        $description = 'Significant portions of the content match existing sources. This may indicate plagiarism.';
                        $color = 'red';
                    } elseif ($plagiarismScore >= 30) {
                        $assessment = 'MODERATE';
                        $description = 'Some parts of the content match existing sources. Review recommended.';
                        $color = 'orange';
                    } else {
                        $assessment = 'LOW';
                        $description = 'Minimal similarity detected with existing sources.';
                        $color = 'yellow';
                    }
                } else {
                    $assessment = 'CLEAN';
                    $description = 'No significant similarities found with existing sources.';
                    $color = 'green';
                    $isClean = true;
                }

                // If assessment is CLEAN, clear any sources that might have been populated
                if ($isClean) {
                    $plagiarismSources = [];
                }
                ?>

                <!-- Plagiarism Report Section -->
                <div id="plagiarism-report" class="content-section">
                    <div class="assessment">
                        <?php if ($plagiarismData): ?>
                            <div class="assessment-details-plagiarize">
                                <p class="rubrics-plagiariaze">Plagiarism Analysis</p>

                                <div class="plagiarism-found">
                                    <div class="plagiarism-summary">
                                        <div class="plagiarism-score-indicator"
                                            style="border-left: 5px solid <?php echo $color; ?>; padding-left: 15px; margin-bottom: 20px;">
                                            <p><strong>Overall Assessment:</strong>
                                                <span style="color: <?php echo $color; ?>; font-weight: bold;">
                                                    <?php echo htmlspecialchars($assessment); ?>
                                                </span>
                                            </p>
                                            <p><strong>Similarity Score:</strong>
                                                <span style="color: <?php echo $color; ?>; font-weight: bold;">
                                                    <?php echo htmlspecialchars(number_format($plagiarismScore, 2)) . '%'; ?>
                                                </span>
                                            </p>
                                            <p><strong>Verdict:</strong> <?php echo htmlspecialchars($description); ?></p>
                                        </div>

                                        <?php if (isset($plagiarismData["total_sources_analyzed"]) || isset($plagiarismData["total_parts"])): ?>
                                            <div class="plagiarism-stats">
                                                <p><small>
                                                        <?php if (isset($plagiarismData["total_parts"])): ?>
                                                            Parts Analyzed:
                                                            <?php echo htmlspecialchars($plagiarismData["total_parts"]); ?>
                                                        <?php endif; ?>
                                                        <?php if (isset($plagiarismData["total_sources_found"])): ?>
                                                            | Sources Found:
                                                            <?php echo htmlspecialchars($plagiarismData["total_sources_found"]); ?>
                                                        <?php endif; ?>
                                                        <?php if (isset($plagiarismData["total_sources_analyzed"])): ?>
                                                            | Sources Analyzed:
                                                            <?php echo htmlspecialchars($plagiarismData["total_sources_analyzed"]); ?>
                                                        <?php endif; ?>
                                                    </small></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($plagiarismSources) && !$isClean): ?>
                                        <div class="plagiarism-other-sources">
                                            <p class="plagiarized-works-database"
                                                style="margin-top: 25px; margin-bottom: 15px; font-weight: bold; color: #1b4242;">
                                                📋 Matching Sources Found (<?php echo count($plagiarismSources); ?>):
                                            </p>
                                            <ol class="source-list" style="counter-reset: source-counter;">
                                                <?php foreach ($plagiarismSources as $index => $source): ?>
                                                    <li
                                                        style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #dc3545;">
                                                        <div class="source-header">
                                                            <p style="margin: 0 0 8px 0;">
                                                                <!-- <strong style="color: #1b4242;">
                                                                    <?php
                                                                    $title = $source["title"] ?? 'Untitled Source';
                                                                    if (empty(trim($title))) {
                                                                        $title = 'Source #' . ($index + 1);
                                                                    }
                                                                    echo htmlspecialchars($title);
                                                                    ?>
                                                                </strong> -->
                                                            </p>
                                                        </div>

                                                        <?php
                                                        // Handle multiple URL formats
                                                        $urls = [];
                                                        if (isset($source["url"])) {
                                                            if (is_array($source["url"])) {
                                                                $urls = array_filter($source["url"], function ($url) {
                                                                    return !empty(trim($url));
                                                                });
                                                            } elseif (!empty(trim($source["url"]))) {
                                                                $urls = [$source["url"]];
                                                            }
                                                        }
                                                        ?>

                                                        <?php if (!empty($urls)): ?>
                                                            <div class="source-urls" style="margin-top: 8px;">
                                                                <p style="margin: 0 0 5px 0; font-weight: 600; color: #495057;">
                                                                    🔗 Source URL<?php echo count($urls) > 1 ? 's' : ''; ?>:
                                                                </p>
                                                                <ul class="plagiarism-source-urls"
                                                                    style="margin: 0; padding-left: 20px;">
                                                                    <?php foreach ($urls as $url): ?>
                                                                        <?php if (!empty(trim($url))): ?>
                                                                            <li style="margin-bottom: 5px; word-break: break-all;">
                                                                                <a href="<?php echo htmlspecialchars(trim($url)); ?>"
                                                                                    target="_blank" rel="noopener noreferrer"
                                                                                    style="color: #007bff; text-decoration: none;">
                                                                                    <?php echo htmlspecialchars(trim($url)); ?>
                                                                                </a>
                                                                            </li>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (isset($source["matched_parts"]) && !empty($source["matched_parts"])): ?>
                                                            <div class="matched-content" style="margin-top: 12px;">
                                                                <p style="margin: 0 0 8px 0; font-weight: 600; color: #495057;">
                                                                    📝 Matched Content:
                                                                </p>
                                                                <div
                                                                    style="background: #fff3cd; padding: 10px; border-radius: 4px; border: 1px solid #ffeaa7;">
                                                                    <?php foreach ($source["matched_parts"] as $part): ?>
                                                                        <p style="margin: 0 0 8px 0; font-style: italic; color: #856404;">
                                                                            "<?php echo htmlspecialchars($part); ?>"
                                                                        </p>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ol>
                                        </div>
                                    <?php elseif ($isClean): ?>
                                        <div class="no-sources-clean"
                                            style="margin-top: 20px; padding: 15px; background: #d4edda; border-radius: 8px; border: 1px solid #c3e6cb;">
                                            <p style="margin: 0; color: #155724;">
                                                ✅ <strong>Clean Content:</strong> No matching sources were found. This content
                                                appears to be original.
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-sources"
                                            style="margin-top: 20px; padding: 15px; background: #d4edda; border-radius: 8px; border: 1px solid #c3e6cb;">
                                            <p style="margin: 0; color: #155724;">
                                                📋 No specific matching sources were identified in the analysis.
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($plagiarismScore > 50 && !$isClean): ?>
                                        <div class="plagiarism-warning"
                                            style="margin-top: 20px; padding: 15px; background: #f8d7da; border-radius: 8px; border: 1px solid #f5c6cb;">
                                            <p style="margin: 0; color: #721c24; font-weight: bold;">
                                                ⚠️ <strong>High Similarity Warning:</strong> This content shows significant
                                                similarity to existing sources.
                                                Please review your institution's academic integrity policies.
                                            </p>
                                        </div>
                                    <?php elseif ($plagiarismScore > 25 && !$isClean): ?>
                                        <div class="plagiarism-caution"
                                            style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffeaa7;">
                                            <p style="margin: 0; color: #856404;">
                                                ⚠️ <strong>Moderate Similarity:</strong> Some portions of this content match
                                                existing sources.
                                                Consider reviewing and citing sources appropriately.
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="assessment-details-plagiarize">
                                <p class="rubrics-plagiariaze">Plagiarism Analysis</p>
                                <div class="plagiarism-unavailable"
                                    style="padding: 20px; background: #e9ecef; border-radius: 8px; text-align: center;">
                                    <p style="margin: 0; color: #6c757d;">
                                        📋 No plagiarism analysis data is available for this submission.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>


                <div
                    class="points-below flex flex-col md:flex-row items-center justify-between gap-4 bg-white shadow-[0_4px_20px_rgba(0,0,0,0.05)] rounded-xl p-6 mt-6 transform transition duration-300 hover:scale-105">
                    <div class="weighted text-center md:text-left">
                        <p class="text-gray-700 text-lg font-medium">
                            Your Total Weighted Score:
                            <span style="color:#9EC8B9;">
                                <?php echo $overallScore !== null ? htmlspecialchars($overallScore) : '0'; ?>%
                            </span>
                        </p>
                    </div>
                    <div class="points text-center md:text-right">
                        <p class="text-gray-700 text-lg font-medium">
                            Your Equivalent Points:
                            <span style=" color: #9EC8B9;">
                                <?php echo ($overallScore / 100) * $result[0]["points_per_item"]; ?> Points
                            </span>
                        </p>
                    </div>
                </div>
            </div>
            <script>
                // Function to show the selected section and highlight the active nav link
                function showSection(sectionId, clickedLink) {
                    // Hide all content sections
                    document.querySelectorAll('.content-section').forEach(function (section) {
                        section.classList.remove('active');
                    });

                    // Show the selected section
                    document.getElementById(sectionId).classList.add('active');

                    // Remove 'active' class from all navigation links
                    document.querySelectorAll('.nav-bar a').forEach(function (link) {
                        link.classList.remove('active');
                    });

                    // Add 'active' class to the clicked link
                    clickedLink.classList.add('active');
                }

                // Show the System Assessment section by default
                document.addEventListener('DOMContentLoaded', function () {
                    showSection('system-assessment', document.getElementById('nav-system-assessment'));
                });
            </script>



            <?php
            // At the top after database connection
            $stmt = $conn->prepare("SELECT * FROM essay_evaluations WHERE answer_id = :answer_id");
            $stmt->bindParam(':answer_id', $answer_id, PDO::PARAM_INT);
            $stmt->execute();
            $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $teacher_comment = !empty($evaluations) ? ($evaluations[0]['teacher_comment'] ?? '') : '';

            function extractEvaluationOnly($feedback)
            {
                // Pattern to match everything before ❌ or ✅ followed by "Why"
                $pattern = '/^(.*?)(?:\s*[❌✅]\s*Why\s+)/s';

                if (preg_match($pattern, $feedback, $matches)) {
                    return trim($matches[1]);
                }

                // Fallback: if no pattern matches, return original feedback
                return $feedback;
            }
            ?>

            <!-- In your HTML 
            <div class="comments">
                <?php if (!empty(trim($teacher_comment))): ?>
                    <h2>Quiz Creator Comment</h2>
                    <div class="comment">
                        <p class="comment-text"><?php echo htmlspecialchars($teacher_comment); ?></p>
                        <div class="educators">
                        </div>
                    </div>
                <?php endif; ?>
            </div> -->



            <script>
                document.addEventListener("DOMContentLoaded", function () {
                    document.querySelector('.feedback-container').classList.add('show');
                    document.querySelectorAll('.assessment, .comments').forEach(function (el) {
                        el.classList.add('show');
                    });
                });
            </script>
        </body>

</html>