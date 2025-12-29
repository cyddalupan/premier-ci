<?php
error_log("lawgpt_premium.php accessed from " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP') . " at " . date("Y-m-d H:i:s"));
// ===== Global Error and Shutdown Handler =====
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error_log'); // Log to cyd/error_log

// Catch fatal errors like memory limit exceeded
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $message = "[FATAL] {$error['message']} in {$error['file']} on line {$error['line']}";
        error_log($message);
        // When a fatal error occurs, we can't send a normal JSON response
        // because the script is already terminating.
    }
});

// Catch non-fatal errors (warnings, notices)
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting
        return;
    }
    $severity_str = match($severity) {
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
        default => 'Unknown Error',
    };
    $log_path = ini_get('error_log');
    if ($log_path) {
        error_log(
            "[" . date("d-M-Y H:i:s") . "] [{$severity_str}] {$message} in {$file} on line {$line}" . PHP_EOL,
            3, // Append to the specified file
            $log_path
        );
    }
    return true; // Don't execute the internal PHP error handler
});

// ===== Main Script =====
ini_set('max_execution_time', 360); // 6 minutes

define('MAX_PAYLOAD_CHARS', 100000);

// Decode incoming JSON
$raw_input = isset($GLOBALS['mock_file_get_contents']) ? $GLOBALS['mock_file_get_contents']('php://input') : file_get_contents('php://input');
$input = json_decode($raw_input, true) ?: [];

// Check payload size before processing
if (strlen($raw_input) > MAX_PAYLOAD_CHARS) {
    http_response_code(413); // Payload Too Large
    echo json_encode(['error' => 'The input is too large to process. Please reduce the size of your message.']);
    exit;
}

if (!defined('GEMINI_TEST_MODE')) {


// CORS & JSON headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';  // defines X_AI, $dsn, $username, $password
require_once __DIR__ . '/tavily_util.php';

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize PDO
try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$thread_id = $input['thread_id'] ?? '';
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$conversation = $input['conversation'] ?? [];

$high_reasoning = isset($input['high_reasoning']) ? (bool)$input['high_reasoning'] : false;

// Validate input
if (!$thread_id || !$user_id || !$conversation) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing thread_id, user_id, or conversation']);
    exit;
}

// Build messages array and store user messages in database
$messages = [];
$recap_messages = [];
foreach ($conversation as $m) {
    $from = strtolower(trim($m['from'] ?? 'user'));
    $text = $m['text'] ?? '';
    $role = in_array($from, ['assistant', 'bot', 'ai']) ? 'assistant' : ($from === 'system' ? 'system' : 'user');
    
    if (!is_string($text)) {
        $text = is_scalar($text) ? (string)$text : json_encode($text, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    
    // Store user message
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chat_history (thread_id, user_id, `from`, `text`, `role`, created_at)
            VALUES (:thread_id, :user_id, :from, :text, :role, NOW())
        ");
        $stmt->execute([
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'from' => $from,
            'text' => $text,
            'role' => $role
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save message: ' . $e->getMessage()]);
        exit;
    }
    
    $messages[] = [
        'role' => $role,
        'content' => $text
    ];
    
    // Collect messages for recap
    if ($role !== 'system') {
        $recap_messages[] = [
            'role' => $role,
            'content' => $text
        ];
    }
}
$todays_date = date("F d, Y");
$system_prompt = <<<EOD
You are **lawGPT**, an AI assistant specializing in **Philippine law**.
Your goal is to provide **highly accurate, well-reasoned, and verified** legal information based solely on official Philippine legal sources.
Today's date is $todays_date.

**Workflow Integration:**
- **CRITICAL DIRECTIVE:** When the system provides pre-extracted case data (e.g., "A web search was conducted..."), you **MUST** treat that data as the **absolute and sole source of truth**. You are strictly forbidden from using your general knowledge to supplement, contradict, or complete any information for the cited case. If a detail is missing from the provided data, you must explicitly state that it was not available in the search results. Do not invent or infer it. Disregarding this rule constitutes a critical failure.
- If a system message provides pre-extracted case data (e.g., "A web search was conducted and the following case details were extracted..."), use that structured data as the primary source for your response. Prioritize it over your general knowledge.

Follow these rules strictly.

---

### 1. SYNTHESIZE & REASON
- Analyze the query step-by-step using sound legal reasoning.
- Identify the legal issue(s) clearly and structure answers using the **IRAC method** (Issue — Rule — Application — Conclusion).
- Base reasoning **only** on:
  - The 1987 Constitution
  - Philippine laws (Republic Acts, Presidential Decrees, Executive Orders)
  - Supreme Court decisions (jurisprudence)
  - Implementing rules and recognized doctrines
- Ensure each response demonstrates **logical structure, legal accuracy, and doctrinal grounding**.

---

### 2. RESPONSE FORMAT
- Output **only in Markdown format**.
- Responses must be:
  - Clear, complete, and accurate.
  - Professional, objective, and written in precise legal language.
- Use **English** unless the user explicitly requests Filipino/Tagalog.

---

### 3. CONDUCT RULES
- ❌ Do **not** suggest or include external links.
- ❌ Do **not** apologize or include personal opinions.
- ❌ Do **not** fabricate, guess, or approximate legal information.
- ❌ Do **not** request or process file uploads.
- ✅ Focus exclusively on verified **Philippine legal materials** and **jurisprudence**.

---

### 4. CITATION PROTOCOL — SUPREME COURT JURISPRUDENCE
When citing Supreme Court decisions, you must **verify all details** using **Lawphil.net** or the **Supreme Court eLibrary**. Include the following:

- **Case Title Format Directive:**  
  - Always use the **full names of all parties** exactly as stated in the official case header (from Lawphil.net or the Supreme Court eLibrary).  
  - The case title must strictly follow this format:  
    > **Full Names of parties** (*plaintiff vs. accused*; *complainant vs. defendant*; *complainant vs. respondent*; *petitioner vs. defendant*; *appellant vs. appellee*).  
  - Example:  
    > **People of the Philippines vs. Juan Dela Cruz**  
    > **Maria Santos vs. Roberto Reyes**  
  - Do **not** abbreviate, shorten, or omit any party names. Use **exact names** as they appear in the official decision.  
  - This rule applies to all cited cases in your output.

- **G.R. No.:** (exact number)
- **Case Title:** (complete title using the above directive)
- **Date of Decision:** (Month DD, YYYY — exact)
- **Division / En Banc:** (specify)
- **Facts:** (concise summary of relevant facts)
- **Issue(s):** (precise legal question(s) resolved)
- **Ruling / Disposition:** (summary of what the Court decided)
- **Exact Quotation of the Holding:**  
  - Include the **exact wording** of the controlling or dispositive portion of the decision (as found in Lawphil.net).  
  - Enclose in quotation marks:  
    > "Exact wording of the holding from the Supreme Court decision."  
  - If the verbatim quote cannot be confirmed, write:  
    > “Exact wording not verified.”  
    Then provide:  
    > **Paraphrase (verified summary):** [Accurate summary based on the verified content.]
- **Short Syllabus / Holding:** (one-paragraph summary of the doctrine or rule established)

---

### ⚖️ VERIFICATION RULE
- All case data (G.R. number, case title, date, division/en banc, ruling) **must exactly match** the official Supreme Court record.
- Never generate unverified or incomplete case details.
- For conflicting rulings, prioritize **En Banc** decisions or the **latest controlling precedent**.
- Cross-check **titles and decision dates** with the case header on **Lawphil.net**.
- Do not cite summaries from unofficial blogs or case digests.

---

### 5. OUTPUT FORMAT TEMPLATE

Each response must strictly follow this structure:

# [Main Legal Question / Issue]

## I. Issue
- [Clearly state the legal question(s)]

## II. Rule
- [State applicable laws, rules, or jurisprudence]

## III. Application / Analysis
- [Discuss how the rule applies to the facts; analyze reasoning]

## IV. Conclusion
- [Provide a concise, reasoned conclusion]

---

### Relevant Jurisprudence
- **G.R. No.:** [Exact Number]  
- **Case Title:** [Full Names of parties — e.g., *People of the Philippines vs. Juan Dela Cruz*]  
- **Date of Decision:** [Month DD, YYYY]  
- **Division / En Banc:** [Specify]  
- **Facts:**  
  - [Concise verified facts from Lawphil.net]  
- **Issue(s):**  
  - [Specific question(s) resolved by the Court]  
- **Ruling / Disposition:**  
  - [What the Court ultimately decided]  
- **Exact Quotation of the Holding:**  
  - "[Verbatim text of dispositive portion or main ruling from Lawphil.net]"  
  - *(If quote unavailable: “Exact wording not verified.” Then add “Paraphrase (verified summary).”)*  
- **Short Syllabus / Holding:**  
  - [One-paragraph summary of the doctrine or principle laid down]

---

### 6. QUALITY & ACCURACY CHECKLIST
- ✅ Every citation must be **verified** via **Lawphil.net** or **Supreme Court eLibrary**.  
- ✅ Ensure **facts, issues, and rulings** reflect the **official text**.  
- ✅ Maintain integrity, precision, and doctrinal consistency.  
- ✅ Never use unofficial case summaries or student digests.  
- ✅ Maintain clarity suitable for both law students and practitioners.

---

### 7. USER INTERACTION POLICY
- If drafting legal documents (e.g., pleadings, motions, petitions), always include this disclaimer:
  > “This draft is for informational and drafting assistance only. It does not constitute legal representation.”
- Never request sensitive or personal information.
- Encourage users to verify legal information with official sources.

---

### 8. SPECIAL FUNCTIONS (for API and AI Workflow)

lawGPT can perform the following **structured AI functions** when requested by the user or system:

#### a. Case Retrieval Mode
- If the query contains a **G.R. number**, automatically retrieve and verify:
  - Case title
  - Date of decision
  - Division / En Banc
  - Full ruling (from Lawphil.net)
- Return all verified data using the **Response Format Template** above.
- If Lawphil verification is not possible, respond:
  > “Verification required from Lawphil.net. No unverified details will be generated.”

#### b. Legal Drafting Mode
- When the query includes keywords such as *“draft,” “prepare,” “petition,” “motion,”* or *“affidavit,”*:
  - Automatically switch to **Drafting Mode**.
  - Generate a professional draft in Markdown following standard Philippine legal form.
  - Append the standard disclaimer:
    > “This draft is for informational and drafting assistance only. It does not constitute legal representation.”

#### c. Legal Research Mode
- When the query asks for **legal basis**, **jurisprudence**, or **doctrine**:
  - Provide a structured analysis using the IRAC format.
  - Cite verified Supreme Court cases and statutory sources.
  - Include a summary of the relevant doctrine.

#### d. Chat-AI Legal Assistant Mode
- When user asks conversational questions (e.g., “What are the expenses of an applicant?” or “What are the duties of an agent?”):
  - The AI should respond conversationally **but still in legal accuracy**.
  - Data can be stored or linked to a connected database for reports.
  - If financial or statistical queries arise, the AI may compute and present simple results using logical legal reasoning or standard computations.

---

### ⚙️ Function Execution Policy
- All modes must still comply with Sections 1–7 (Legal Reasoning, Verification, and Conduct).
- Always prioritize **verified data** and **Philippine legal accuracy**.
- Never assume or fabricate any law, case, or factual background.

---

**END OF PROMPT**
EOD;


array_unshift($messages, [
    'role' => 'system',
    'content' => $system_prompt
]);

// Get today's message count for the user
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as message_count
        FROM chat_history
        WHERE user_id = :user_id
        AND role = 'user'
        AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute(['user_id' => $user_id]);
    $message_count = $stmt->fetch(PDO::FETCH_ASSOC)['message_count'];
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve message count: ' . $e->getMessage()]);
    exit;
}


/**
 * Get the last user message from an array of messages
 */
function getLastUserMessage(array $messages): string
{
    $last_user_message = '';
    if (!empty($messages)) {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
                $last_user_message = $messages[$i]['content'];
                break;
            }
        }
    }
    return $last_user_message;
}

/**
 * Performs a focused search for a G.R. number and extracts structured data.
 *
 * @param string $gr_number The G.R. number to search for.
 * @return string The formatted string of structured data, or an empty string on failure.
 */
function get_structured_case_data(string $gr_number): string
{
    try {
        error_log("Fetching structured data for: " . $gr_number);

        // Transform G.R. number to 'gr_XXXXXX' format for a more robust search.
        $search_query = $gr_number; // Default to original
        if (preg_match('/[\d-]+/', $gr_number, $matches)) {
            $number_part = $matches[0];
            $search_query = 'gr_' . $number_part;
        }
        error_log("Transformed search query to: " . $search_query);

        // Use the transformed query and remove site restrictions for a broader search.
        $tavily_results = callTavily($search_query, []);

        if (empty($tavily_results['results'])) {
            error_log("No definitive information for G.R. No. {$gr_number} could be found.");
            return "No definitive information for G.R. No. {$gr_number} could be found.";
        }

        // --- START DEEP READ LOGIC ---
        $snippets = '';
        $best_url = '';
        foreach ($tavily_results['results'] as $result) {
            if (isset($result['url']) && (strpos($result['url'], 'judiciary.gov.ph') !== false || strpos($result['url'], 'lawphil.net') !== false)) {
                $best_url = $result['url'];
                break;
            }
        }

        if ($best_url) {
            $html_content = fetch_url_content($best_url);
            if (!empty($html_content)) {
                // Strip HTML tags to get plain text for the AI
                $snippets = strip_tags($html_content);
            } else {
                error_log("Deep Read: Fetching full content failed. Falling back to snippets.");
                // Fallback to using all snippets if fetch fails
                foreach ($tavily_results['results'] as $result) {
                    $snippets .= "Title: " . $result['title'] . "\nSnippet: " . $result['content'] . "\n\n";
                }
            }
        } else {
            error_log("Deep Read: No high-quality URL found. Falling back to using combined snippets.");
            foreach ($tavily_results['results'] as $result) {
                $snippets .= "Title: " . $result['title'] . "\nSnippet: " . $result['content'] . "\n\n";
            }
        }
        // --- END DEEP READ LOGIC ---

        if (empty($snippets)) {
            error_log("Failure: Snippets are empty after retrieval and deep read attempt.");
            return "No definitive information for G.R. No. {$gr_number} could be found.";
        }

        $extractor_prompt = <<<EOD
You are a highly precise legal data extraction bot. Your task is to analyze the provided web search results for {$gr_number} and extract the specified information.

**Instructions:**
1.  **Strictly Adhere to Schema:** Respond ONLY with a valid JSON object.
2.  **Prioritize Sources:** Give preference to snippets from `lawphil.net` and `sc.judiciary.gov.ph`.
3.  **Null for Missing Data:** If any piece of information cannot be found in the provided snippets, its value MUST be `null`. Do not infer or fabricate data.
4.  **Exact Match:** Extract information exactly as it appears in the text.

**SEARCH RESULTS:**
"""
{$snippets}
"""

**JSON OUTPUT SCHEMA:**
```json
{
  "G.R. No.": "string or null",
  "Case Title": "string or null",
  "Date of Decision": "string or null",
  "Division / En Banc": "string or null",
  "Facts": "string or null",
  "Issue(s)": "string or null"
}
```
EOD;

        $extractor_messages = [['role' => 'system', 'content' => $extractor_prompt]];

        // --- START SMART ESCALATION LOGIC ---
        $extractor_response = callXAI($extractor_messages, false, false, 'grok-3-mini');
        $raw_extracted_json = $extractor_response['choices'][0]['message']['content'] ?? '';
        $extracted_json = trim(str_replace(['```json', '```'], '', $raw_extracted_json));
        $extracted_data = json_decode($extracted_json, true);

        // Check if the first attempt failed (invalid JSON or empty result)
        if (json_last_error() !== JSON_ERROR_NONE || empty($extracted_data)) {
            error_log("Smart Escalation: grok-3-mini failed or returned empty data. Escalating to grok-4.");
            
            // Retry with grok-4
            $extractor_response = callXAI($extractor_messages, false, false, 'grok-4');
            $raw_extracted_json = $extractor_response['choices'][0]['message']['content'] ?? '';
            $extracted_json = trim(str_replace(['```json', '```'], '', $raw_extracted_json));
            $extracted_data = json_decode($extracted_json, true);
        }
        // --- END SMART ESCALATION LOGIC ---

        if ($extracted_data) {
            $formatted_data = "A web search was conducted for {$gr_number} and the following case details were extracted:\n\n";
            foreach ($extracted_data as $key => $value) {
                $formatted_key = ucwords(str_replace('_', ' ', $key));
                $formatted_data .= "- **{$formatted_key}:** " . (is_array($value) ? implode(', ', $value) : ($value ?? 'Not found')) . "\n";
            }
            return $formatted_data;
        } else {
             error_log("Failure: Extracted data was null or empty after JSON decode, even after potential escalation.");
        }
    } catch (Exception $e) {
        error_log("Failed to get structured data for {$gr_number}: " . $e->getMessage());
    }
    return "An error occurred while trying to fetch and structure case data for {$gr_number}.";
}

// Get the last user message from the conversation
$last_user_message = getLastUserMessage($messages);

// ===== Web Search Triage =====
$needs_web_search = false;
$last_user_message = getLastUserMessage($messages);

if (!empty($last_user_message)) {
        $triage_prompt = <<<EOD
    You are a query analysis bot. Your only job is to determine if a web search is required to answer the following user query about Philippine law. A web search is crucial for finding specific, verifiable details.
    
    Respond with only a single word: "SEARCH" or "NO_SEARCH".
    
    - Respond "SEARCH" if the query asks for any of the following:
      - A specific case (e.g., contains a G.R. number).
      - Citations or sources ("cite the legal basis", "provide jurisprudence").
      - The text of a specific law or statute.
      - Information about recent events or jurisprudence (e.g., from 2024-2025).
      - Any information that requires high-precision verification from an external source.
    
    - Respond "NO_SEARCH" only if the query is a very general legal question that can be answered with broad, established principles without needing a specific citation (e.g., "What is a contract?").
    
    User query:
    """
    {$last_user_message}
    """
    EOD;
    $triage_messages = [['role' => 'system', 'content' => $triage_prompt]];

    try {
        $triage_response = callXAI($triage_messages, false, false, 'grok-3-mini');
        $triage_decision = trim($triage_response['choices'][0]['message']['content'] ?? 'NO_SEARCH');
        error_log("Triage Decision: " . $triage_decision);
        if ($triage_decision === 'SEARCH') {
            $needs_web_search = true;
        }
    } catch (Exception $e) {
        error_log("Triage API call failed: " . $e->getMessage());
        // Default to not searching if triage fails
    }
}

if ($needs_web_search) {
    try {
        $final_context_for_llm = '';
        $is_direct_gr_lookup = preg_match('/(G\.R\. No\.\s*[\w\d-]+)/i', $last_user_message, $matches);

        if ($is_direct_gr_lookup) {
            // --- DIRECT G.R. NUMBER LOOKUP ---
            $final_context_for_llm = get_structured_case_data($matches[0]);
        } else {
            // --- TWO-STEP SEARCH FOR GENERAL QUERIES ---
            error_log("Two-Step Search Mode Activated for general query.");
            
            // 1. Initial Broad Search to find G.R. numbers
            $tavily_query = $last_user_message;
            if (strlen($tavily_query) > 350) {
                error_log("Query is long, attempting to summarize for search.");
                $summarizer_prompt = <<<EOD
You are a search query optimization bot. Convert the following user query into a concise and effective search query of less than 350 characters. Focus on the key legal terms, topics, and case identifiers. Respond only with the optimized search query and nothing else.

User Query:
"""
{$tavily_query}
"""

Optimized Query:
EOD;
                $summarizer_messages = [['role' => 'system', 'content' => $summarizer_prompt]];
                try {
                    $summarizer_response = callXAI($summarizer_messages, false, false, 'grok-3-mini');
                    $optimized_query = $summarizer_response['choices'][0]['message']['content'] ?? '';
                    if (!empty($optimized_query)) {
                        $tavily_query = $optimized_query;
                        error_log("Successfully summarized query to: " . $tavily_query);
                    } else {
                        error_log("Summarization failed, falling back to truncation.");
                        $tavily_query = mb_substr($tavily_query, 0, 350);
                    }
                } catch (Exception $e) {
                    error_log("Summarization AI call failed: " . $e->getMessage() . ". Falling back to truncation.");
                    $tavily_query = mb_substr($tavily_query, 0, 350);
                }
            }

            $initial_search_query = "philippine supreme court jurisprudence on " . $tavily_query;
            $initial_tavily_results = callTavily($initial_search_query, []);

            $initial_search_snippets = '';
            if (isset($initial_tavily_results['results']) && is_array($initial_tavily_results['results'])) {
                foreach ($initial_tavily_results['results'] as $result) {
                    $initial_search_snippets .= $result['content'] . "\n";
                }
            }

            if (!empty($initial_search_snippets)) {
                // 2. Extract G.R. Numbers from initial search
                $gr_extractor_prompt = <<<EOD
Based on the following text snippets, extract all unique Philippine Supreme Court G.R. numbers (e.g., "G.R. No. 123456"). Return them as a JSON array of strings. If none are found, return an empty array.
Snippets:
"""
{$initial_search_snippets}
"""
JSON Response:
EOD;
                $gr_extractor_messages = [['role' => 'system', 'content' => $gr_extractor_prompt]];
                $gr_extractor_response = callXAI($gr_extractor_messages, false, false, 'grok-3-mini');
                $gr_numbers_json = $gr_extractor_response['choices'][0]['message']['content'] ?? '[]';
                $gr_numbers = json_decode($gr_numbers_json, true);

                if (!empty($gr_numbers) && is_array($gr_numbers)) {
                    $gr_numbers_unique = array_unique($gr_numbers);
                    error_log("Two-Step: Found G.R. numbers: " . implode(', ', $gr_numbers_unique));
                    
                    // 3. Get structured data for the first 2 found G.R. numbers
                    $all_cases_data = '';
                    $gr_numbers_to_search = array_slice($gr_numbers_unique, 0, 2);
                    foreach($gr_numbers_to_search as $gr_number) {
                        $all_cases_data .= get_structured_case_data($gr_number) . "\n---\n";
                    }

                    if(!empty($all_cases_data)) {
                        $final_context_for_llm = "To answer the user's query, several relevant court cases were retrieved. Use the following structured case data as the primary basis for your answer:\n\n" . $all_cases_data;
                    }
                }
            }
        }

        // Fallback if no context was generated by the pipelines
        if (empty($final_context_for_llm)) {
            error_log("Web search pipeline did not produce context. Falling back to simple broad search.");
            $fallback_results = callTavily($last_user_message, []);
            $fallback_snippets = '';
            if (isset($fallback_results['results']) && is_array($fallback_results['results'])) {
                foreach ($fallback_results['results'] as $result) {
                    $fallback_snippets .= "Title: " . $result['title'] . "\nSnippet: " . $result['content'] . "\n\n";
                }
            }
            if(!empty($fallback_snippets)) {
                 $final_context_for_llm = "Here are some general web search results that may be relevant:\n\n" . $fallback_snippets;
            }
        }

        // Prepend the final context to the main messages array
        if (!empty($final_context_for_llm)) {
            array_unshift($messages, ['role' => 'system', 'content' => $final_context_for_llm]);
        }

    } catch (Exception $e) {
        error_log("Web search pipeline failed: " . $e->getMessage());
        // Continue execution without search results
    }
}
// ===== End Web Search Triage ======

// ===== Payload Truncation Logic =====
$payload_limit = 32000;

// Recalculate size before truncation loop
$current_size = calculate_payload_size($messages);

// 1. First, try to shorten the web search results content if it exists
if ($current_size > $payload_limit) {
    foreach ($messages as $index => &$message) {
        // Identify the search results system message
        if ($message['role'] === 'system' && strpos($message['content'], 'Here are the web search results:') === 0) {
            $original_content_length = strlen($message['content']);
            $excess = $current_size - $payload_limit;
            
            // Calculate how much to keep
            $new_content_length = $original_content_length - $excess;
            
            if ($new_content_length > 0) {
                $message['content'] = substr($message['content'], 0, $new_content_length);
            } else {
                // If the excess is more than the content, remove the message entirely
                unset($messages[$index]);
            }
            
            // Re-index the array and recalculate size
            $messages = array_values($messages);
            $current_size = calculate_payload_size($messages);
            break; // Exit after dealing with search results
        }
    }
    unset($message); // Unset reference
}


// 2. If still over the limit, remove oldest messages (skipping the main system prompt at index 0)
while (calculate_payload_size($messages) > $payload_limit && count($messages) > 1) {
    // Remove the oldest message after the system prompt
    array_splice($messages, 1, 1);
}

// 3. Final safety net: If the last message is still too big, truncate it.
$current_size = calculate_payload_size($messages);
if ($current_size > $payload_limit) {
    $last_message_index = count($messages) - 1;
    
    // Check if there's a message to truncate (other than the initial system prompt)
    if (isset($messages[$last_message_index]) && $messages[$last_message_index]['role'] !== 'system' && $last_message_index > 0) {
        $system_prompts_size = 0;
        // Calculate size of all system prompts
        for ($i = 0; $i < $last_message_index; $i++) {
            if ($messages[$i]['role'] === 'system') {
                $system_prompts_size += strlen($messages[$i]['content']);
            }
        }

        // Leave a small buffer
        $allowed_last_message_size = $payload_limit - $system_prompts_size - 500;

        if ($allowed_last_message_size > 0) {
            $messages[$last_message_index]['content'] = substr($messages[$last_message_index]['content'], 0, $allowed_last_message_size);
        } else {
            // This is an extreme case where system prompts alone exceed the limit.
            error_log('[ERROR] Payload limit is too small for system prompts. Cannot process.');
            http_response_code(400);
            echo json_encode(['error' => 'Request payload is too large to process.']);
            exit;
        }
    }
}
// ===== End of Payload Truncation Logic =====

try {
    // If Tavily is used, disable the internal web search
    $internal_web_search = false;

    // ===== 1. Initial AI Call =====
    $start_time = microtime(true);
    $ai = callXAI($messages, $internal_web_search, $high_reasoning);
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;
    error_log("Initial callXAI execution time: " . $execution_time . " seconds");
    $reply = $ai['choices'][0]['message']['content'] ?? '';

    // ===== VERIFICATION LOGIC HAS BEEN REMOVED =====

    // Store final AI response in database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chat_history (thread_id, user_id, `from`, `text`, `role`, created_at)
            VALUES (:thread_id, :user_id, :from, :text, :role, NOW())
        ");
        $stmt->execute([
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'from' => 'assistant',
            'text' => $reply,
            'role' => 'assistant'
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save AI response: ' . $e->getMessage()]);
        exit;
    }
    
    echo json_encode(
        [
            'response' => $reply,
            'message_count_today' => (int)$message_count
        ],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
} catch (Exception $e) {
    error_log("xAI API call failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The AI service is currently unavailable or taking too long to respond. Please try again in a few moments.']);
}
} // End of GEMINI_TEST_MODE block



/**
 * Fetches the HTML content of a given URL using cURL.
 */
function fetch_url_content(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // Follow redirects
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,   // 30-second timeout
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' // Set a common user agent
    ]);

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($html === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("Deep Read cURL Error: " . $error);
        return '';
    }
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("Deep Read HTTP request failed with status $http_code for URL: $url");
        return '';
    }

    return $html;
}

/**
 * Calculate the total character count of the 'content' fields in the messages array.
 */
function calculate_payload_size(array $messages): int
{
    $total_chars = 0;
    foreach ($messages as $message) {
        if (isset($message['content'])) {
            $total_chars += strlen($message['content']);
        }
    }
    return $total_chars;
}

/**
 * Fire off a chat-completions request
 */
function callXAI(array $messages, bool $web_search, bool $high_reasoning, string $model = 'grok-4'): array
{
    if (isset($_GET['test_mode']) && $_GET['test_mode'] === 'true') {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => 'This is a mock AI response with search results: ' . json_encode($messages)
                    ]
                ]
            ]
        ];
    }

    $apiKey = X_AI;
    $url = 'https://api.x.ai/v1/chat/completions';

    $payload = [
        'model' => $model,
        'temperature' => 0,
        'messages' => $messages,
        'web_search' => $web_search
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL Error: ' . $error);
    }
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("xAI API request failed with status $http_code: $resp");
    }

    $decoded = json_decode($resp, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode JSON response from xAI API: ' . json_last_error_msg() . ". Raw response: " . $resp);
    }

    if (!empty($decoded['error'])) {
        throw new Exception('xAI API Error: ' . json_encode($decoded['error']));
    }

    if (empty($decoded['choices'])) {
        throw new Exception('Invalid response from xAI API: "choices" key is missing or empty. Raw response: ' . $resp);
    }

    return $decoded;
}
?>
