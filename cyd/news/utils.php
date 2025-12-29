<?php
// utils function
function countScoreByGroup($courses_results)
{
	$score_0_25 = $score_25_50 = $score_50_75 = $score_75_100 = 0;
	$totalScore = 0;
	$count = count($courses_results);

	foreach ($courses_results as $course) {
		$score = $course['average_score'];
		$totalScore += $score;

		if ($score >= 0 && $score < 30) {
			$score_0_25++;
		} elseif ($score >= 31 && $score < 60) {
			$score_25_50++;
		} elseif ($score >= 61 && $score < 84) {
			$score_50_75++;
		} elseif ($score >= 85 && $score <= 100) {
			$score_75_100++;
		}
	}

	$averageScore = $count ? ($totalScore / $count) : 0;

	return [
		'0_25' => $score_0_25,
		'25_50' => $score_25_50,
		'50_75' => $score_50_75,
		'75_100' => $score_75_100,
		'average' => $averageScore,
	];
}