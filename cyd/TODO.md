The primary goal is to eliminate silent failures in the `cyd/api/lawgpt_premium.php` script, which currently crashes on large inputs without logging errors. We will implement a comprehensive, global error-trapping mechanism to ensure all errors (including fatal ones) are logged. We will also bolster the payload truncation logic to make it foolproof.

Concurrently, we will refactor `cyd/error_viewer.php` to a "display and clear" model: it will show all existing logs and then automatically truncate them.

All changes will be verified with new or updated unit tests within the existing `cyd` testing framework to guarantee correctness and prevent future regressions.

### Investigation Summary & Blockers

During the implementation and testing of the global error handler for `lawgpt_premium.php`, several issues were encountered, leading to a detailed investigation:

1.  **Initial Test Failure (Missing `config.php`):**
    *   **Problem:** The test failed because `lawgpt_premium.php`'s `require '../config.php'` could not find the file in the test environment.
    *   **Resolution:** Modified `lawgpt_premium.php` to use `require __DIR__ . '/../config.php';` for absolute path resolution. Modified `LawGPTPremiumTest.php` to create a mock `config.php` in `setUp()` and clean it in `tearDown()`.

2.  **Second Test Failure (Script `exit`ing):**
    *   **Problem:** Even with the mock `config.php`, the script `lawgpt_premium.php` was executing its main logic (including PDO connection and `exit` calls) when included by the test, terminating PHPUnit.
    *   **Resolution:** Modified `lawgpt_premium.php` to wrap its main execution logic in `if (!defined('GEMINI_TEST_MODE')) { ... }`. Modified `LawGPTPremiumTest.php` to `define('GEMINI_TEST_MODE', true);` in `setUp()`.

3.  **Subsequent Test Failures (Log file not created):**
    *   **Problem:** After resolving execution issues, the test consistently failed because the `test_error.log` file was not created by the custom error handler.
    *   **Investigation Steps:**
        *   **Error Reporting Level:** Added `error_reporting(E_ALL);` to `lawgpt_premium.php` to ensure all errors are passed to the handler. (No change in outcome).
        *   **Explicit `error_log`:** Modified the custom error handler to use `error_log(..., 3, $log_path)` to explicitly force appending to a file. (No change in outcome).
        *   **Filesystem Writable Check:** Added `file_put_contents` sanity check to `LawGPTPremiumTest.php`. This confirmed the test process *could* write to the target directory. (Sanity check passed, but error handler still didn't write).
        *   **PHPUnit Interference:** Suspected PHPUnit's error handling was interfering. Rewrote `LawGPTPremiumTest.php` to execute a self-contained PHP script via `shell_exec`. This script would define its own error handler and trigger a warning, completely isolated from PHPUnit's direct control.
        *   **Final Diagnostic Test:** The self-contained script, which defined its own error handler and triggered a warning, *also* failed to create the log file. Output: `Log file was not created by the self-contained script. Failed asserting that file "/root/testonly/academy/cyd/tests/test_error.log" exists.`

4.  **Current Blocker:**
    *   **Conclusion:** The repeated failures across multiple testing strategies, including a completely isolated PHP process, strongly indicate a fundamental environment-level issue. The PHP `error_log()` function (and potentially `file_put_contents` when called from within an error handler context in this specific environment) is being prevented from writing to a file, despite `ini_set` directives and explicit function calls. This is likely due to system-wide PHP configuration, security policies, or container restrictions that override runtime settings.
    *   **Impact:** The goal of logging errors to a file via `error_log` is currently blocked by the environment.

### Micro-Steps

*   **Part 1: Solidify `lawgpt_premium.php`**
    *   `- [x] **Implement Global Error Handling:**`
        *   `- [x] In `cyd/api/lawgpt_premium.php`, add a `set_error_handler` function to convert all PHP notices and warnings into catchable errors that get logged.`
        *   `- [x] In the same script, add a `register_shutdown_function` to catch and log fatal errors (e.g., memory exhaustion) that would otherwise fail silently.`
        *   `- [x] Added `error_reporting(E_ALL);` to ensure all errors are caught.`
        *   `- [x] Modified `error_log` call to be explicit (`type 3`) and include timestamp.`
        *   `- [x] Wrapped main script logic in `if (!defined('GEMINI_TEST_MODE')) { ... }` for testability.`
    *   `- [x] **Add Final Payload Truncation:**`
        *   `- [x] Add a final "safety-net" truncation step after the message-removal loop to handle cases where a single message exceeds the payload limit.`
    *   `- [ ] **Create Unit Tests for `lawgpt_premium.php`:**`
        *   `- [ ] Create a new test file: `cyd/tests/LawGPTPremiumTest.php`.
        *   `- [ ] Write a test that intentionally triggers a PHP warning and asserts that the error message is correctly written to the designated log file.`
        *   `- [ ] Run the new test suite via `cd cyd && ./vendor/bin/phpunit tests/LawGPTPremiumTest.php` to confirm it passes.`
        *   **Status:** This step is currently **BLOCKED** by an environment issue preventing file-based error logging. Further investigation into the PHP environment configuration (e.g., `php.ini`, web server configuration, container settings) is required to resolve this.

*   **Part 2: Rework `error_viewer.php`**
    *   `- [ ] **Refactor to "Display and Clear":**`
        *   `- [ ] Remove the `clean_log_file()` function from `cyd/error_viewer.php`.
        *   `- [ ] Create a new `truncate_log_file()` function that safely empties a file's contents.`
        *   `- [ ] Modify the script's main execution flow to call `truncate_log_file()` for all log files immediately after their content has been displayed.`
    *   `- [ ] **Update Unit Tests for `error_viewer.php`:**`
        *   `- [ ] Modify `cyd/tests/ErrorViewerTest.php` to test the new "display and clear" functionality.`
        *   `- [ ] The test will involve creating a dummy log file, calling the viewer's logic, and then asserting that the dummy log file is empty (size 0) after the call.`
        *   `- [ ] Run the updated tests via `cd cyd && ./vendor/bin/phpunit tests/ErrorViewerTest.php` to verify the changes.`