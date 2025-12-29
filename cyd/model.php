<?php
if (!defined('ENV')) {
    define('ENV', 'prod');
}
if (ENV == "dev") {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

function fetchRandomQuestion($pdo, $userId, $courseId, $is_practice = false)
{
    try {
        if ($is_practice) {
            $query = "SELECT q.q_id, q.q_question, q.q_answer FROM quiz_new q ORDER BY RAND() LIMIT 1";
        } else {
            $query = "
                SELECT q.q_id, q.q_question, q.q_answer 
                FROM quiz_new q
                WHERE q.q_course_id = :courseId AND NOT EXISTS (
                    SELECT 1 FROM diag_ans d 
                    WHERE d.question_id = q.q_id AND d.user_id = :userId AND d.batch_id = :courseId
                )
                ORDER BY RAND() LIMIT 1";
        }

        $stmt = $pdo->prepare($query);
        if (!$is_practice) {
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':courseId', $courseId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    } catch (PDOException $e) {
        logMessage("fetchRandomQuestion error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        return null;
    }
}

function getExpectedAnswer($pdo, $questionId)
{
    try {
        $query = "SELECT q_answer, q_question FROM quiz_new WHERE q_id = :questionId";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':questionId', $questionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        logMessage("getExpectedAnswer error for questionId=$questionId: " . $e->getMessage());
        return null;
    }
}

function insertAnswer($pdo, $userId, $questionId, $userInput, $course_id, $score, $feedback)
{
    try {
        $query = "INSERT INTO diag_ans (user_id, batch_id, question_id, answer, score, feedback, date_created) VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId, $course_id, $questionId, $userInput, $score, $feedback]);
    } catch (PDOException $e) {
        logMessage("insertAnswer error for userId=$userId, questionId=$questionId: " . $e->getMessage());
    }
}

function countUserAnswers($pdo, $userId, $courseId)
{
    try {
        $query = "SELECT COUNT(*) AS answer_count FROM diag_ans WHERE user_id = :userId AND batch_id = :courseId";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':courseId', $courseId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['answer_count'] : 0;
    } catch (PDOException $e) {
        logMessage("countUserAnswers error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        return 0;
    }
}

function getAllUserAnswers($pdo, $userId, $courseId)
{
    try {
        $query = "
            SELECT da.*, q.q_question
            FROM diag_ans da
            JOIN quiz_new q ON da.question_id = q.q_id
            WHERE da.user_id = :userId AND da.batch_id = :courseId";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':courseId', $courseId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logMessage("getAllUserAnswers error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        return [];
    }
}

function getRemainingSeconds($pdo, $userId, $course_id) {
    try {
        $query = "SELECT remaining_seconds FROM custom_users_course WHERE user_id = :userId AND course_id = :course_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['remaining_seconds' => 0];
    } catch (PDOException $e) {
        logMessage("getRemainingSeconds error for userId=$userId, course_id=$course_id: " . $e->getMessage());
        return ['remaining_seconds' => 0];
    }
}

function createUserCourse($pdo, $userId, $courseId, $totalQuestions, $timer_minutes)
{
    try {
        $remainingSeconds = max(0, ($timer_minutes * 60) * $totalQuestions);
        $query = "INSERT INTO custom_users_course (user_id, course_id, remaining_seconds, date_created)
                  VALUES (:userId, :courseId, :remainingSeconds, NOW())";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':courseId', $courseId, PDO::PARAM_INT);
        $stmt->bindParam(':remainingSeconds', $remainingSeconds, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        logMessage("createUserCourse error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        return false;
    }
}

function updateRemainingSeconds($pdo, $userId, $remainingSeconds, $courseId)
{
    try {
        $remainingSeconds = max(0, $remainingSeconds);
        $query = "UPDATE custom_users_course SET remaining_seconds = :remainingSeconds WHERE user_id = :userId AND course_id = :courseId";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':remainingSeconds', $remainingSeconds, PDO::PARAM_INT);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':courseId', $courseId, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        logMessage("updateRemainingSeconds error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        return false;
    }
}

function updateSummary($pdo, $userId, $course_id, $average, $summary)
{
    try {
        $query = "UPDATE custom_users_course SET average_score = :average, summary = :summary WHERE user_id = :userId AND course_id = :course_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':average', $average, PDO::PARAM_STR);
        $stmt->bindParam(':summary', $summary, PDO::PARAM_STR);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        logMessage("updateSummary error for userId=$userId, course_id=$course_id: " . $e->getMessage());
        return false;
    }
}

function hasSummary($pdo, $userId, $course_id) {
    try {
        $query = "SELECT COUNT(*) FROM custom_users_course WHERE user_id = :userId AND course_id = :course_id AND summary IS NOT NULL";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        logMessage("hasSummary error for userId=$userId, course_id=$course_id: " . $e->getMessage());
        return false;
    }
}

function hasCompletedDiagnosticAndCourse($pdo, $userId) {
    try {
        $queryDiagnostic = "SELECT COUNT(*) FROM custom_users_course WHERE user_id = :userId AND course_id = 0 AND summary IS NOT NULL";
        $stmt = $pdo->prepare($queryDiagnostic);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $diagnosticCompleted = $stmt->fetchColumn() > 0;

        $queryCourse = "SELECT COUNT(*) FROM custom_users_course WHERE user_id = :userId AND course_id != 0 AND summary IS NOT NULL";
        $stmt = $pdo->prepare($queryCourse);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $courseCompleted = $stmt->fetchColumn() > 0;

        return $diagnosticCompleted && $courseCompleted;
    } catch (PDOException $e) {
        logMessage("hasCompletedDiagnosticAndCourse error for userId=$userId: " . $e->getMessage());
        return false;
    }
}

function getCurrentUser($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        logMessage("getCurrentUser error for user_id=$user_id: " . $e->getMessage());
        return null;
    }
}
