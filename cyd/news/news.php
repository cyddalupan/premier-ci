<?php
require '../config.php';
require 'model.php';
require 'utils.php';

$dsn = DSN_PATH;

try {
	$pdo = new PDO($dsn, $username, $password);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$courses_results = getLast200CustomUsersCourses($pdo);
	$score_counts = countScoreByGroup($courses_results);
	$top_scores = getTop3Scores($pdo);
	$lowest_scores = getLowest3Scores($pdo);
	$quizData = getQuizData($pdo);
} catch (PDOException $e) {
	echo "Connection failed: " . $e->getMessage() . PHP_EOL;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<?php require '../style-over.php'; ?>
	<title>Dashboard</title>
</head>

<body>
	<div class="container">
		<div class="header">
			<h1>Total Average Score: <?php echo number_format($score_counts['average'], 2); ?>%</h1>
		</div>

		<div class="row text-center mb-3">
			<div class="col-md-3">0 - 30: <?php echo $score_counts['0_25']; ?></div>
			<div class="col-md-3">31 - 60: <?php echo $score_counts['25_50']; ?></div>
			<div class="col-md-3">61 - 84: <?php echo $score_counts['50_75']; ?></div>
			<div class="col-md-3">85 - 100: <?php echo $score_counts['75_100']; ?></div>
		</div>

		<div class="row">
			<div class="col-md-3">
				<div class="chart-container">
					<canvas id="scoreChart"></canvas>
				</div>
			</div>

			<div class="col-md-9">
				<div class="summary">
					<h2>Top 3 Students</h2>
					<div class="row">
						<?php foreach ($top_scores as $index => $student): ?>
							<div class="col-4">
								<div class="card mb-3">
									<div class="card-body">
										<h5 class="card-title">Avg Score:
											<?php echo htmlspecialchars(round($student['average_score'])); ?>%
										</h5>
										<p class="card-text small">
											<?php echo htmlspecialchars(mb_substr($student['summary'], 0, 200) . (strlen($student['summary']) > 200 ? '...' : '')); ?>
										</p>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="summary">
					<h2>Lowest 3 Students</h2>
					<div class="row">
						<?php foreach ($lowest_scores as $index => $student): ?>
							<div class="col-4">
								<div class="card mb-3">
									<div class="card-body">
										<h5 class="card-title">
											Avg Score: <?php echo htmlspecialchars(round($student['average_score'])); ?>%
										</h5>
										<p class="card-text small">
											<?php echo htmlspecialchars(mb_substr($student['summary'], 0, 200) . (strlen($student['summary']) > 200 ? '...' : '')); ?>
										</p>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<table class="table table-bordered">
			<thead>
				<tr>
					<th>Average Score</th>
					<th>Course Title</th>
					<th>Take Count</th>
					<th>Question</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($quizData as $quiz): ?>
					<tr>
						<td><?php echo htmlspecialchars(round($quiz['average_score'])); ?>%</td>
						<td>
							<?php if ($quiz['q_course_id'] == 0): ?>
								Diagnostic Exam
							<?php else: ?>
								<a href="#" data-bs-toggle="tooltip"
									data-bs-title="<?php echo htmlspecialchars($quiz['title']); ?>">
									<?php echo htmlspecialchars(mb_strimwidth($quiz['title'], 0, 30, '...')); ?>
								</a>
							<?php endif; ?>
						</td>
						</td>
						<td>
							<a href="#" data-bs-toggle="tooltip" data-bs-html="true"
								data-bs-title="<?php echo htmlspecialchars(nl2br(implode("\n\n---\n\n", explode('; ', $quiz['student_answers'])))); ?>">
								<?php echo htmlspecialchars($quiz['take_count']); ?>
							</a>
						</td>
						<td>
							<a href="#" data-bs-toggle="tooltip"
								data-bs-title="<?php echo htmlspecialchars($quiz['q_question']); ?>">
								<?php echo htmlspecialchars(mb_strimwidth($quiz['q_question'], 0, 150, '...')); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php require 'script.php'; ?><!-- Include Bootstrap JS -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
			var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
				return new bootstrap.Tooltip(tooltipTriggerEl);
			});
		});
	</script>
</body>

</html>