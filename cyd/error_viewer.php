<?php
// Security check
$expected_token = 'a3k9d2p5j8f1g7h4';
$provided_token = isset($_GET['token']) ? $_GET['token'] : '';

if ($provided_token !== $expected_token) {
    header('HTTP/1.1 403 Forbidden');
    die('Access Denied');
}

echo "<h1>Error Log Viewer</h1>";

require_once __DIR__ . '/error_viewer_functions.php';

// Use relative paths to ensure portability
$root_log = __DIR__ . '/../error_log';
$cyd_log = __DIR__ . '/error_log';
$api_log = __DIR__ . '/api/error_log';

display_log($root_log);
truncate_log_file($root_log);

display_log($cyd_log);
truncate_log_file($cyd_log);

display_log($api_log);
truncate_log_file($api_log);

?>