<?php
// Turn off PHP warnings in output
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// CORS & JSON headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require '../config.php';  // defines X_AI, $dsn, $username, $password
require '../vendor/autoload.php';  // For smalot/pdfparser

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get user_id from POST
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

// Validate file upload and user_id
if ($user_id <= 0 || !isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user_id or invalid file']);
    exit;
}

// Validate file type and size (max 20MB)
$allowed_types = ['text/plain', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
$file_type = mime_content_type($_FILES['doc_file']['tmp_name']);
$file_size = $_FILES['doc_file']['size'];
$file_ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
if (!in_array($file_type, $allowed_types) || $file_size > 20 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type (TXT, PDF, DOCX, JPG, PNG) or size exceeds 20MB']);
    exit;
}

// Temp path for processing
$temp_path = sys_get_temp_dir() . '/' . basename($_FILES['doc_file']['name']);
if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $temp_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process file']);
    exit;
}

$text = '';

// Non-AI conversion
switch ($file_ext) {
    case 'txt':
        $text = file_get_contents($temp_path);
        break;
    case 'pdf':
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($temp_path);
            $text = $pdf->getText();
        } catch (Exception $e) {
            // Fallback to AI if parsing fails
            $text = convertWithAI($temp_path, $file_type);
        }
        break;
    case 'docx':
        // Extract text from DOCX (ZIP format)
        $zip = new ZipArchive();
        if ($zip->open($temp_path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $xml = str_replace('</w:r></w:p></w:tc><w:tc>', " ", $xml);
            $text = strip_tags($xml);
        } else {
            // Fallback to AI
            $text = convertWithAI($temp_path, $file_type);
        }
        break;
    case 'jpg':
    case 'jpeg':
    case 'png':
        // Resize if needed, then AI
        $resized_path = resizeImage($temp_path, $file_ext);
        $text = convertWithAI($resized_path ?: $temp_path, $file_type);
        if ($resized_path && $resized_path !== $temp_path) unlink($resized_path);
        break;
    default:
        $text = convertWithAI($temp_path, $file_type);  // Fallback
}

// Clean up temp file
unlink($temp_path);

// Return extracted text
echo json_encode(['success' => true, 'extracted_text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/**
 * Resize image if too small (<512 pixels) or too large (>1024 max dimension)
 */
function resizeImage($file_path, $ext) {
    list($width, $height) = getimagesize($file_path);
    $pixels = $width * $height;

    $min_pixels = 512;
    $max_dim = 1024;

    if ($pixels >= $min_pixels && $width <= $max_dim && $height <= $max_dim) {
        return $file_path;  // Already OK
    }

    // Calculate scale for min pixels (upscale) or max dim (downscale)
    if ($pixels < $min_pixels) {
        $min_size = 32;  // Base for upscale
        $scale = max($min_size / $width, $min_size / $height);
    } else {
        $scale = min($max_dim / $width, $max_dim / $height);
    }

    $new_width = round($width * $scale);
    $new_height = round($height * $scale);

    $source = match ($ext) {
        'jpg', 'jpeg' => imagecreatefromjpeg($file_path),
        'png' => imagecreatefrompng($file_path),
        default => null
    };

    if (!$source) return $file_path;  // GD failed, use original

    $resized = imagecreatetruecolor($new_width, $new_height);
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    $resized_path = sys_get_temp_dir() . '/resized_' . basename($file_path);
    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($resized, $resized_path, 85),  // 85% quality to reduce size
        'png' => imagepng($resized, $resized_path, 6),  // Compression level 6
    };

    imagedestroy($source);
    imagedestroy($resized);

    return $resized_path;
}

/**
 * Use Grok-4 for AI conversion (e.g., OCR for images)
 */
function convertWithAI($file_path, $file_type) {
    $base64 = base64_encode(file_get_contents($file_path));
    $messages = [
        ['role' => 'system', 'content' => 'Extract all text from the uploaded file using OCR if necessary, and provide a detailed description of what\'s in the image. Format the response as: Extracted Text: [text] Description: [description]'],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Extract text and describe this file.'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $file_type . ';base64,' . $base64]]
        ]]
    ];

    $apiKey = X_AI;
    $url = 'https://api.x.ai/v1/chat/completions';

    $payload = [
        'model' => 'grok-4',
        'temperature' => 0,
        'messages' => $messages
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
    if ($resp === false) {
        return 'AI conversion failed: ' . curl_error($ch);
    }
    curl_close($ch);

    $decoded = json_decode($resp, true);
    if (isset($decoded['error'])) {
        return 'AI conversion failed: ' . json_encode($decoded['error']);
    }
    return $decoded['choices'][0]['message']['content'] ?? 'AI conversion failed';
}
?>
