<?php

function display_log($log_file) {
    if (file_exists($log_file)) {
        echo "<h2>Log: " . htmlspecialchars($log_file) . "</h2>";
        $log_content = file_get_contents($log_file);
        if (!empty($log_content)) {
            echo "<pre>" . htmlspecialchars($log_content) . "</pre>";
        } else {
            echo "<p>Log file is empty.</p>";
        }
    } else {
        echo "<h2>Log file not found: " . htmlspecialchars($log_file) . "</h2>";
    }
}

function truncate_log_file($log_file) {
    if (file_exists($log_file)) {
        file_put_contents($log_file, '');
        echo "<p>Truncated log file: " . htmlspecialchars($log_file) . "</p>";
    }
}

?>