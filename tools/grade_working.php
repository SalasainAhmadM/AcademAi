<?php

set_time_limit(300);
ini_set('memory_limit', '512M');

class EssayEvaluator
{
  private $conn;
  private $kiss = "";

  public function __construct($connection)
  {
    $this->conn = $connection;
  }

  /**
   * Detect AI-generated content in essay
   */
  public function detectAIContent($essay, $apiUrl = 'https://kaizokuDev.pythonanywhere.com/analyze')
  {
    // Validate input
    if (empty($essay) || str_word_count($essay) < 20) {
      return ['error' => 'Essay too short for accurate analysis (minimum 20 words)'];
    }

    // Prepare the API request data
    $data = ['essay' => $essay];

    // Initialize cURL session with better error handling
    $ch = curl_init($apiUrl);
    if (!$ch) {
      return ['error' => 'Failed to initialize cURL session'];
    }

    // Set cURL options with timeout and SSL verification
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
      ],
      CURLOPT_TIMEOUT => 60,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => false, // Consider enabling in production
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3
    ]);

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Check for cURL errors
    if ($curlError) {
      return ['error' => 'API connection error: ' . $curlError];
    }

    // Process the response
    if ($httpCode !== 200) {
      return ['error' => "API returned error code: {$httpCode}", 'response' => $response];
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return ['error' => 'Invalid JSON response: ' . json_last_error_msg()];
    }

    // Validate required fields
    if (!isset($result['ai_probability']) || !isset($result['human_probability'])) {
      return ['error' => 'Missing required fields in API response'];
    }

    // Format the percentages with 2 decimal places
    $aiPercentage = number_format($result['ai_probability'], 2);
    $humanPercentage = number_format($result['human_probability'], 2);

    // Return the formatted string and the raw values
    return [
      'formatted' => "AI Generated: {$aiPercentage}% and Human: {$humanPercentage}%",
      'ai_probability' => $result['ai_probability'],
      'human_probability' => $result['human_probability'],
      'explanation' => $result['explanation'] ?? 'No explanation provided.'
    ];
  }

  /**
   * Check for plagiarism in essay
   */
  public function checkPlagiarism($essay, $api2Url, $googleApiKey = null, $googleCx = null)
  {
    // Validate input
    if (empty($essay)) {
      return ['success' => false, 'error' => 'Essay text is empty'];
    }

    // Prepare the request data
    $data = ['text' => $essay];

    // Add optional API credentials if provided
    if ($googleApiKey) {
      $data['api_key'] = $googleApiKey;
    }
    if ($googleCx) {
      $data['cx'] = $googleCx;
    }

    // Encode the data as JSON
    $jsonData = json_encode($data);
    if (!$jsonData) {
      return ['success' => false, 'error' => 'Failed to encode request data'];
    }

    // Initialize cURL session
    $ch = curl_init($api2Url);
    if (!$ch) {
      return ['success' => false, 'error' => 'Failed to initialize cURL session'];
    }

    // Set cURL options with better error handling
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $jsonData,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
      ],
      CURLOPT_TIMEOUT => 90,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3
    ]);

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Check for errors
    if ($curlError) {
      return ['success' => false, 'error' => "cURL Error: {$curlError}"];
    }

    if ($httpCode !== 200) {
      return [
        'success' => false,
        'error' => "HTTP Error: {$httpCode}",
        'response' => $response
      ];
    }

    // Decode the JSON response
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return [
        'success' => false,
        'error' => 'Invalid JSON response: ' . json_last_error_msg(),
        'response' => $response
      ];
    }

    return $result;
  }

  /**
   * Initialize database table for essay evaluations
   */
  public function initializeDatabase()
  {
    try {
      $this->conn->exec("
                CREATE TABLE IF NOT EXISTS essay_evaluations (
                    evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
                    answer_id INT NOT NULL,
                    student_id INT NOT NULL,
                    question_id INT NOT NULL,
                    quiz_id INT NOT NULL,
                    overall_score DECIMAL(5,2) DEFAULT 0,
                    ai_probability DECIMAL(5,2) DEFAULT 0,
                    human_probability DECIMAL(5,2) DEFAULT 0,
                    plagiarism_score DECIMAL(5,2) DEFAULT 0,
                    plagiarism_sources JSON,
                    evaluation_data JSON,
                    evaluation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_answer_id (answer_id),
                    INDEX idx_student_id (student_id),
                    INDEX idx_question_id (question_id),
                    INDEX idx_quiz_id (quiz_id),
                    UNIQUE KEY unique_answer (answer_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
      return true;
    } catch (PDOException $e) {
      throw new Exception('Failed to initialize database: ' . $e->getMessage());
    }
  }

  /**
   * Get quiz details with validation
   */
  public function getQuizDetails($quizId)
  {
    try {
      $stmt = $this->conn->prepare("SELECT * FROM quizzes WHERE quiz_id = ?");
      $stmt->execute([$quizId]);
      $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$quiz) {
        throw new Exception('Quiz not found');
      }

      return $quiz;
    } catch (PDOException $e) {
      throw new Exception('Database error: ' . $e->getMessage());
    }
  }

  /**
   * Get questions for a quiz
   */
  public function getQuizQuestions($quizId)
  {
    try {
      $stmt = $this->conn->prepare("SELECT * FROM essay_questions WHERE quiz_id = ?");
      $stmt->execute([$quizId]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      throw new Exception('Failed to get quiz questions: ' . $e->getMessage());
    }
  }

  /**
   * Get rubric data for a question
   */
  public function getRubricData($rubricId)
  {
    try {
      $stmt = $this->conn->prepare("SELECT data, id FROM rubrics WHERE subject_id = ?");
      $stmt->execute([$rubricId]);
      $rubricData = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$rubricData) {
        throw new Exception('Rubric not found');
      }

      $decodedData = json_decode($rubricData["data"], true);
      if (!$decodedData) {
        throw new Exception('Invalid rubric data format');
      }

      return $decodedData;
    } catch (PDOException $e) {
      throw new Exception('Failed to get rubric data: ' . $e->getMessage());
    }
  }

  /**
   * Format rubric criteria for API consumption
   */
  public function formatRubricCriteria($decodedData)
  {
    $criteriaFormatted = "";

    // Append headers
    $criteriaFormatted .= "Headers:\n";
    foreach ($decodedData["headers"] as $index => $header) {
      $criteriaFormatted .= "{$index}: {$header}\n";
    }
    $criteriaFormatted .= "\n";

    // Append rows with criteria and cells
    $criteriaFormatted .= "Rows:\n";
    foreach ($decodedData["rows"] as $index => $row) {
      $criteriaFormatted .= "Criteria {$index}: {$row["criteria"]}\n";
      $criteriaFormatted .= "  Cells:\n";
      foreach ($row["cells"] as $cellIndex => $cellValue) {
        $cellValue = $cellValue === "" ? "(empty)" : $cellValue;
        $criteriaFormatted .= "    {$cellIndex}: {$cellValue}\n";
      }
      $criteriaFormatted .= "\n";
    }

    return $criteriaFormatted;
  }

  /**
   * Get answers for a specific question
   */
  public function getQuestionAnswers($questionId, $quizId)
  {
    try {
      $stmt = $this->conn->prepare("
                SELECT qa.*, a.first_name, a.last_name 
                FROM quiz_answers qa 
                JOIN quiz_participation qp ON qa.quiz_taker_id = qp.quiz_taker_id
                JOIN academai a ON qp.user_id = a.creation_id
                WHERE qa.question_id = ? AND qp.quiz_id = ?
            ");
      $stmt->execute([$questionId, $quizId]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      throw new Exception('Failed to get question answers: ' . $e->getMessage());
    }
  }

  /**
   * Format plagiarism information for display
   */
  public function formatPlagiarismInfo($plagiarismResult)
  {
    $plagiarismInfo = "";

    if (isset($plagiarismResult['success']) && $plagiarismResult['success']) {
      $plagiarismInfo .= "Overall plagiarism score: " . ($plagiarismResult['overall_percentage'] ?? 'N/A') . "%\n";
      $plagiarismInfo .= "Assessment: " . ($plagiarismResult['assessment'] ?? 'N/A') . "\n";

      // Info about matching sources
      if (isset($plagiarismResult['sources']) && !empty($plagiarismResult['sources'])) {
        foreach ($plagiarismResult['sources'] as $index => $source) {
          $plagiarismInfo .= "Source " . ($index + 1) . ": " . ($source['title'] ?? 'Unknown') . "\n";
          $plagiarismInfo .= "URL: " . ($source['link'] ?? 'N/A') . "\n";
          $plagiarismInfo .= "Maximum similarity: " . (($source['max_similarity'] ?? 0) * 100) . "%\n";
          $plagiarismInfo .= "-------------------------\n";
        }
      } else {
        $plagiarismInfo .= "No matching sources found.\n";
      }
    } else {
      $plagiarismInfo .= "No plagiarism data available.\n";
    }

    return $plagiarismInfo;
  }

  /**
   * Prepare evaluation prompt for API
   */
  public function prepareEvaluationPrompt($criteriaFormatted, $decodedData, $question, $essayText, $plagiarismInfo)
  {
    return "Description: " . $question['question'] . "\n" .
      $essayText .
      '<add also reason,here is additional result' .
      $plagiarismInfo .
      '>(Note: if essay is too low and criterita is invalid, return json blank and score, and always strictly response as json format. The JSON structure should follow excatly:
                Re -evaluate the ai score  ,use bert model,gptzero, etc determine the score of ai and human text probability, depends on deepest words patterns , add reason and do not label creator or student because anyone can use as quiz participant,deduct points if necessary 
                                The formula for the total score is:
Total Score = Sum(Criterion Performance Level % × Criterion Weight)
Assign a Rating → Choose a rating for each criterion based on the rubric:

Excellent = 5
Good = 4
Satisfactory = 3
Needs Improvement = 2
Poor = 1
Convert the Weights to Decimal Form:

15% → 0.15
20% → 0.20
Multiply the Rating by the Weight for each criterion.
Example:

Thesis Statement: Rating = 4, Weight = 15% →
4 × 0.15 = 0.6
Use of Evidence: Rating = 5, Weight = 20% →
5 × 0.20 = 1.0
Add Up the Weighted Scores:

Final Score = 0.6 + 1.0 + ... = 3.6
Convert to Percentage:
(3.6 / 5) × 100 = 72%

{
"criteria_scores": {
"Criteria 1 (Conciseness)": {
"score": [SCORE],
"feedback": [FEEDBACK],
"suggestions": [
[SUGGESTION 1],
[SUGGESTION 2]
]
},
"Criteria 2 (Conciseness)": {
"score": [SCORE],
"feedback": [FEEDBACK],
"suggestions": [
[SUGGESTION 1],
[SUGGESTION 2]
]
},
[REPEAT FOR OTHER CRITERIA]
},
"overall_weighted_score": [SCORE],
"general_assessment": {
"strengths": [
[STRENGTH 1],
[STRENGTH 2],
[STRENGTH 3]
],
"areas_for_improvement": [
[AREA 1],
[AREA 2],
[AREA 3]
]
}
}
>(Note: The score for each criterion should be a percentage (0-100) OF the criterion\'s weight. For example, if a criterion has a weight of 20% and performance is excellent, the score should be 20 (100% of 20%). If performance is average, the score might be 10 (50% of 20%). 
        make a reference base to the answer of each question essay that put by creator Creator benchmark: (Creator benchmark:' . $this->kiss . ')
        The overall_weighted_score should be the sum of all criteria scores, with a maximum possible value of 100. 
      
strictly follow the format of example output:
    also rate if the essay is ai generated or human, use berta model,gptzero or grammarly, add benchmark then add reason why need score deduction)';
  }

  /**
   * Call evaluation API
   */
  public function callEvaluationAPI($data, $url = 'https://kaizokuDev.pythonanywhere.com/evaluate')
  {
    $ch = curl_init($url);
    if (!$ch) {
      throw new Exception('Failed to initialize cURL session for evaluation');
    }

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($data))
      ],
      CURLOPT_TIMEOUT => 120,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
      throw new Exception('Evaluation API error: ' . $curlError);
    }

    if ($httpCode !== 200) {
      throw new Exception("Evaluation API returned HTTP {$httpCode}");
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new Exception('Invalid JSON response from evaluation API');
    }

    return $result;
  }

  /**
   * Process all essays for evaluation
   */
  public function processEssayEvaluations($quizId)
  {
    // Validate quiz ID
    if (!$quizId || !is_numeric($quizId)) {
      throw new Exception('Invalid quiz ID');
    }

    // Initialize database
    $this->initializeDatabase();

    // Get quiz details
    $quiz = $this->getQuizDetails($quizId);

    // Get all questions for this quiz
    $questions = $this->getQuizQuestions($quizId);
    if (empty($questions)) {
      throw new Exception('No questions found for this quiz');
    }

    $allResults = [];
    $api2Url = 'https://kaizokuDev.pythonanywhere.com/check_plagiarism';
    $googleApiKey = null;
    $googleCx = null;

    foreach ($questions as $question) {
      // Build reference answer
      $this->kiss .= "Reference answer for number 1: " . ($question["answer"] ?? '') . "\n";

      // Get rubric data
      $decodedData = $this->getRubricData($question['rubric_id']);
      $criteriaFormatted = $this->formatRubricCriteria($decodedData);

      // Get answers for this question
      $answers = $this->getQuestionAnswers($question['essay_id'], $quizId);

      // Process each answer
      foreach ($answers as $answer) {
        try {
          // Clean the essay text
          $essayText = trim($answer['answer_text'] ?? '');
          if (empty($essayText)) {
            continue; // Skip empty answers
          }

          // Get plagiarism check result
          $plagiarismResult = $this->checkPlagiarism($essayText, $api2Url, $googleApiKey, $googleCx);

          // Get AI detection result
          $aiResult = $this->detectAIContent($essayText);

          // Format plagiarism info
          $plagiarismInfo = $this->formatPlagiarismInfo($plagiarismResult);

          // Prepare evaluation data
          $evaluationPrompt = $this->prepareEvaluationPrompt(
            $criteriaFormatted,
            $decodedData,
            $question,
            $essayText,
            $plagiarismInfo
          );

          $apiData = [
            'rubrics_criteria' => $criteriaFormatted,
            'level' => $decodedData["headers"],
            'essay' => $evaluationPrompt
          ];

          // Call evaluation API
          $evaluationResult = $this->callEvaluationAPI($apiData);

          // Store results
          $allResults[] = [
            'answer' => $answer,
            'evaluation' => $evaluationResult,
            'ai' => $aiResult,
            'plagiarism' => $plagiarismResult,
            'essay' => $essayText,
            'question_id' => $question['essay_id'],
            'quiz_id' => $quizId
          ];

        } catch (Exception $e) {
          // Log error but continue processing other answers
          error_log("Error processing answer {$answer['answer_id']}: " . $e->getMessage());

          $allResults[] = [
            'answer' => $answer,
            'error' => $e->getMessage(),
            'question_id' => $question['essay_id'],
            'quiz_id' => $quizId
          ];
        }
      }
    }

    return $allResults;
  }
}

// Main execution
try {
  // Get quiz ID from URL parameter with validation
  $quiz_id = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT);
  if (!$quiz_id) {
    throw new Exception('Invalid or missing quiz ID');
  }

  // Initialize database connection
  require_once '../classes/connection.php';
  $db = new Database();
  $conn = $db->connect();

  // Create evaluator instance
  $evaluator = new EssayEvaluator($conn);

  // Process all evaluations
  $allResults = $evaluator->processEssayEvaluations($quiz_id);

  // Store results in session and redirect
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  $_SESSION['saveResults'] = $allResults;

  // Optional: Update quiz participation status
  /*
  if (isset($_GET["quiz_taker"])) {
      $quiz_taker = filter_input(INPUT_GET, 'quiz_taker', FILTER_VALIDATE_INT);
      if ($quiz_taker) {
          $updateStatus = $conn->prepare("UPDATE quiz_participation SET status = 'completed' WHERE quiz_taker_id = ?");
          $updateStatus->execute([$quiz_taker]);
      }
  }
  */

  header('Location: grade2.php');
  exit;

} catch (Exception $e) {
  // Enhanced error handling
  error_log("Essay evaluation error: " . $e->getMessage());

  // Return JSON error response for AJAX requests
  if (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
  ) {
    header('Content-Type: application/json');
    echo json_encode([
      'success' => false,
      'error' => $e->getMessage(),
      'timestamp' => date('Y-m-d H:i:s')
    ]);
  } else {
    // For regular requests, show user-friendly error
    echo "<h1>Error</h1>";
    echo "<p>An error occurred while processing the essay evaluations.</p>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='javascript:history.back()'>Go Back</a></p>";
  }
  exit;
}

?>