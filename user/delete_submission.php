<?php
include('../classes/connection.php');
session_start();

if (isset($_GET['student_id'], $_GET['quiz_id'], $_GET['quiz_taker_id'])) {
    $studentId = $_GET['student_id'];
    $quizId = $_GET['quiz_id'];
    $quizTakerId = $_GET['quiz_taker_id'];

    $db = new Database();
    $conn = $db->connect();

    if (!$conn) {
        die("Database connection failed: " . print_r($db->getError(), true));
    }

    try {
        $conn->beginTransaction();

        $stmt1 = $conn->prepare("DELETE FROM quiz_answers WHERE quiz_taker_id = :quiz_taker_id");
        $stmt1->bindParam(':quiz_taker_id', $quizTakerId, PDO::PARAM_INT);
        $stmt1->execute();

        $stmt2 = $conn->prepare("DELETE FROM quiz_participation WHERE quiz_taker_id = :quiz_taker_id AND quiz_id = :quiz_id AND user_id = :student_id");
        $stmt2->bindParam(':quiz_taker_id', $quizTakerId, PDO::PARAM_INT);
        $stmt2->bindParam(':quiz_id', $quizId, PDO::PARAM_INT);
        $stmt2->bindParam(':student_id', $studentId, PDO::PARAM_INT);
        $stmt2->execute();

        $conn->commit();

        header("Location: AcademAI-Library-Leaderboard.php?quiz_id=$quizId");
        exit;

    } catch (PDOException $e) {
        // Roll back if something fails
        $conn->rollBack();
        echo "Error: " . $e->getMessage();
    }

} else {
    echo "Missing required parameters.";
}
?>