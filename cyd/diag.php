<?php
require 'config.php';
require 'utils.php';
require 'model.php';
require 'mail.php';

if (ENV == "dev") {
	header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
	header("Cache-Control: post-check=0, pre-check=0", false);
	header("Pragma: no-cache");
}

$progressPercentage = 0;
$allow_practice = false;
$userId = null;
$courseId = 0;
$answerCount = 0;
$totalQuestions = 6;
$answers = [];
$averageScore = 0;
$timer_minutes = 12;
$score = 100;
$feedback = "";
$remainingSeconds = 9999;


$is_practice = isset($_GET['is_practice']) && $_GET['is_practice'] === 'true';
$is_mock = isset($_GET['is_mock']) && $_GET['is_mock'] === 'true';

try {
	$pdo = new PDO($dsn, $username, $password);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		$userId = isset($_POST['userId']) ? $_POST['userId'] : null;
        $courseId = isset($_POST['courseId']) ? $_POST['courseId'] : 0;

        if ($is_practice && hasCompletedDiagnosticAndCourse($pdo, $userId)) {
            $allow_practice = true;
        }
    
        if (isset($_POST['start']) || isset($_POST['continue']) || isset($_POST['skip'])) {
            $result = fetchRandomQuestion($pdo, $userId, $courseId, $is_practice);
            $question = $result['q_question'] ?? "No data found.";
            $questionId = $result['q_id'] ?? null;
        } elseif (isset($_POST['userInput'])) {
            $userInput = $_POST['userInput'];
            $questionId = $_POST['questionId'];
    
            $quiz_result = getExpectedAnswer($pdo, $questionId);
            $expected = $quiz_result['q_answer'];
            $question = $quiz_result['q_question'];
    
            $response = callOpenAI($userInput, $expected);
    
            if (isset($response['choices'][0]['message']['function_call'])) {
                processResponse($pdo, $userId, $questionId, $userInput, $courseId, $response, $is_practice, $score, $feedback);
            } else {
                echo "Response: " . $response['choices'][0]['message']['content'] . PHP_EOL;
            }
        }

		// Manage Timer
		$remainingSeconds = manageTimer($pdo, $userId, $courseId, $is_practice, $totalQuestions, $timer_minutes);

		$progressPercentage = calculateProgress($pdo, $userId, $courseId, $is_practice, $remainingSeconds, $totalQuestions, $answerCount);

		if ($progressPercentage == 100) {
			finalizeAssessment($pdo, $userId, $courseId, $totalQuestions, $answers, $averageScore);
		}
	}
} catch (PDOException $e) {
	echo "Connection failed: " . $e->getMessage() . PHP_EOL;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Quiz Application</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <?php require 'style-over.php'; ?>
    <style>
        .fixed-top-timer {
            margin: auto;
            width: 89px;
            top: 7px;
            box-shadow: 4px 10px 15px;
        }
    </style>
    <?php // require 'style.php'; ?>
</head>

<body>
    <?php 
    $showTimer = isset($questionId) && $progressPercentage !== 100 && (!isset($_POST['userInput']) || isset($_POST['skip'])) && !$is_practice;
    if ($showTimer): ?>
    <div id="timer" class="d-flex justify-content-center fixed-top bg-white p-2 rounded border fixed-top-timer">
        <div id="minutes" class="badge bg-primary mx-1">00</div>
        <div>:</div>
        <div id="seconds" class="badge bg-primary mx-1">00</div>
    </div>
    <?php endif; ?>
    
    <div id="content" class="container mt-5">
        <div class="card">
            <div class="card-header bg-primary text-white">Quiz Question</div>

            <div class="card-body">
                <?php 
                $isPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
                if ($isPostRequest && $progressPercentage !== 100 && !$is_practice): ?>
                <div class="progress mb-3">
                    <div class="progress-bar" role="progressbar" style="width: <?= $progressPercentage; ?>%;" aria-valuenow="<?= $progressPercentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="text-center mb-3">
                    <?= $answerCount; ?> out of <?= $totalQuestions; ?> questions answered
                </div>
                <?php endif; ?>

                <?php if ($isPostRequest && isset($_POST['userInput']) && $progressPercentage !== 100 && !isset($_POST['skip'])): ?>
                <!-- Result Page -->
                <div class="alert alert-secondary p-3">
                    <p><strong>Score:</strong> <?= htmlspecialchars($score); ?>%</p>
                    <p><strong>Question:</strong> <?= htmlspecialchars($question); ?>%</p>
                    <p><strong>Your Answer:</strong> <?= $userInput; ?></p>
                    <p><strong>Feedback:</strong> <?= $feedback; ?></p>
                    <form method="post" action="">
                        <input type="hidden" name="userId" id="userIdInput">
                        <input type="hidden" name="courseId" id="courseIdInput">
                        <button id="submitButton" type="submit" name="continue" class="btn btn-primary">Continue</button>
                    </form>
                </div>
                <?php elseif (!isset($userId)): ?>
                <!-- Start Page -->
                <div class="mb-3 lead">
                    <p>
                        <?php if ($courseId !== 0 || $is_mock): ?>
                            Start your mock exam.
                        <? elseif ($is_practice): ?>
                            Start your practice exam to be well-prepared for the bar.
                        <? else: ?>
                            Start your diagnostic exam to evaluate your knowledge and skills.
                        <?php endif; ?>
                    </p>
                </div>
                <form method="post" action="">
                    <input type="hidden" name="userId" id="userIdInput">
                    <input type="hidden" name="courseId" id="courseIdInput">
                    <button id="submitButton" type="submit" name="start" class="btn btn-primary">
                        <?php if ($courseId !== 0 || $is_mock): ?>
                            Start Mock Exam
                        <? elseif ($is_practice): ?>
                            Start Practice Exam
                        <? else: ?>
                            Start Diagnostic Exam
                        <?php endif; ?>
                    </button>
                </form>
                <?php elseif (isset($questionId) && $progressPercentage !== 100 && (!$is_practice || $allow_practice)): ?>
                <!-- Q&A Page -->
                <form method="post" action="">
                    <input type="hidden" name="userId" id="userIdInput">
                    <input type="hidden" name="courseId" id="courseIdInput">
                    <input type="hidden" name="questionId" value="<?= htmlspecialchars($questionId); ?>">
                    <input type="hidden" id="remaining-seconds" name="remaining-seconds">
                    <div class="form-group mb-3">
                        <p><?= htmlspecialchars($question); ?></p>
                        <textarea name="userInput" class="form-control bg-light border" rows="8" placeholder="Your answer here..."></textarea>
                    </div>
                    <button id="submitButton" type="submit" class="btn btn-primary">Submit Answer</button>
                    <button id="skipButton" skip="submitButton" type="submit" name="skip" class="btn btn-secondary ms-2">Skip</button>
                </form>
                <?php elseif (isset($questionId) && $progressPercentage !== 100 && !$allow_practice): ?>
                <div class="alert alert-warning" role="alert">
                    You can't take the practice exam at the moment. You need to complete the diagnostic first and finish one course.
                </div>
                <?php endif; ?>

                <?php if (!empty($answers)): ?>
                <h3>Grade: <?= round($averageScore); ?> / 100 </h3>
                <div class="accordion" id="accordionExample">
                    <?php foreach ($answers as $index => $answer): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading-<?= $index; ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                data-bs-target="#collapse-<?= $index; ?>" aria-expanded="false"
                                aria-controls="collapse-<?= $index; ?>">
                                Score: <?= $answer['score']; ?>%
                            </button>
                        </h2>
                        <div id="collapse-<?= $index; ?>" class="accordion-collapse collapse"
                            aria-labelledby="heading-<?= $index; ?>" data-bs-parent="#accordionExample">
                            <div class="accordion-body">
                                <p><strong>Question:</strong> <?= htmlspecialchars($answer['q_question']); ?></p>
                                <hr>
                                <p><strong>Answer:</strong> <?= htmlspecialchars($answer['answer']); ?></p>
                                <hr>
                                <p><strong>Feedback:</strong> <?= $answer['feedback']; ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div id="loadingSpinner" class="spinner-border text-primary position-absolute top-50 start-50" role="status"
            style="display: none;">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    <?php require 'scripts.php'; ?>
</body>

</html>