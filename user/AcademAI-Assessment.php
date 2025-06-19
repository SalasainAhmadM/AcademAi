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

                                                // Initialize level
                                                $level = '';

                                                // Use regex to extract the level from the feedback
                                                if (preg_match('/✅\s+Why\s+(\w[\w\s]*\w):/i', $criteriaData["feedback"], $matches)) {
                                                    $level = trim($matches[1]);
                                                }

                                                // Compare header and level
                                                $levelNumber = null;
                                                if (isset($criteriaDatas['headers']) && is_array($criteriaDatas['headers'])) {
                                                    foreach ($criteriaDatas['headers'] as $index => $header) {
                                                        if (strcasecmp($header, $level) === 0) {
                                                            $levelNumber = $index + 1; // Add 1 to make it human-readable (1-based index)
                                                            break;
                                                        }
                                                    }
                                                }

                                                echo "<br>Level: " . htmlspecialchars($level);
                                                if ($levelNumber !== null) {
                                                    echo " (" . htmlspecialchars($levelNumber) . ")";
                                                }
                                                ?>
                                            </p>
                                        </div>


                                        <div class="assess-feedback col-5">
                                            <p class="rubrics-explanation"><strong>Evaluation:</strong></p>
                                            <?php
                                            // Convert line break placeholder to actual <br> tags
                                            $criteriaData["feedback"] = str_replace("**", "<br>", $criteriaData["feedback"]);
                                            echo $criteriaData["feedback"];
                                            ?>
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
                        <?php if (isset($data["ai_detection"]) && !empty($data["ai_detection"])): ?>
                            <div class="assessment-details-ai">
                                <p class="rubrics-ai">AI Detection Analysis</p>
                                <div class="ai-score-container">
                                    <div class="ai-score-chart">
                                        <div class="ai-meter">
                                            <div class="ai-portion"
                                                style="width: <?php echo htmlspecialchars($data["ai_detection"]["ai_probability"] * 100); ?>%;">
                                                <span class="ai-label">AI:
                                                    <?php echo htmlspecialchars($data["ai_detection"]["ai_probability"] * 100); ?>%</span>
                                            </div>
                                            <div class="human-portion"
                                                style="width: <?php echo htmlspecialchars($data["ai_detection"]["human_probability"] * 100); ?>%;">
                                                <span class="human-label">Human:
                                                    <?php echo htmlspecialchars($data["ai_detection"]["human_probability"] * 100); ?>%</span>
                                            </div>
                                        </div>

                                        <div class="ai-explanation">
                                            <br>
                                            <h4>Detailed Explanation:</h4>
                                            <?php if (isset($data["ai_detection"]["explanation"])): ?>
                                                <div class="ai-meter">
                                                    <?php
                                                    echo nl2br(htmlspecialchars(
                                                        preg_replace([
                                                            '/```json/',       // Remove opening code block
                                                            '/```/',           // Remove closing code block
                                                            '/,+/',            // Remove extra commas (1 or more)
                                                            '/\bJSON\b/',      // Remove the word JSON
                                                            '/\b[A-Z]{3,}\b/'  // Remove all-caps words (3 or more letters)
                                                        ], '', $data["ai_detection"]["explanation"])
                                                    ));
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
                                    <h4>What does this mean?</h5>
                                        <p>This analysis estimates the probability that the text was generated by AI versus
                                            written by a human. A higher AI percentage suggests the content may have been
                                            created or heavily assisted by AI tools like ChatGPT or similar models.</p>

                                        <?php if ($data["ai_detection"]["ai_probability"] > 70): ?>
                                            <div class="ai-warning">
                                                <p><strong>Note:</strong> This content shows a high probability of AI
                                                    generation. If this work was submitted as original human work, please review
                                                    your institution's policies on AI-assisted writing.</p>
                                            </div>
                                        <?php elseif ($data["ai_detection"]["ai_probability"] > 40): ?>
                                            <div class="ai-caution">
                                                <p><strong>Note:</strong> This content shows moderate indicators of AI
                                                    assistance. The writing may contain sections created with AI help.</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="ai-ok">

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
                                                                <strong style="color: #1b4242;">
                                                                    <?php
                                                                    $title = $source["title"] ?? 'Untitled Source';
                                                                    if (empty(trim($title))) {
                                                                        $title = 'Source #' . ($index + 1);
                                                                    }
                                                                    echo htmlspecialchars($title);
                                                                    ?>
                                                                </strong>
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

            <!-- In your HTML -->
            <div class="comments">
                <?php if (!empty(trim($teacher_comment))): ?>
                    <h2>Quiz Creator Comment</h2>
                    <div class="comment">
                        <p class="comment-text"><?php echo htmlspecialchars($teacher_comment); ?></p>
                        <div class="educators">
                            <!-- ... instructor info ... -->
                        </div>
                    </div>
                <?php endif; ?>
            </div>



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