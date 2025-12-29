<?php
require '../config.php';

if (ENV == "dev") {
	header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
	header("Cache-Control: post-check=0, pre-check=0", false);
	header("Pragma: no-cache");
}

$apiKey = OPEN_AI;

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $messages = [];
        for ($i = 10; $i >= 1; $i--) {
            $messageVar = "message_$i";
            $replyVar = "reply_$i";
            if (!empty($_POST[$replyVar])) {
                $messages[] = ["role" => "assistant", "content" => $_POST[$replyVar]];
            }
            if (!empty($_POST[$messageVar])) {
                $messages[] = ["role" => "user", "content" => $_POST[$messageVar]];
            }
        }

        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        array_unshift($messages, [
            "role" => "system",
            "content" => "You are LawGPT you are running inside 'TOPBAR ASSIST PH' which is a online course for law students. Discuss only Philippine law from 1989 to June 2024 or this website. Redirect any off-topic questions back to this subject. If there is a chance, promote this current website as a helpful resource for studying for bar exams. Keep reply short and just plain text no markdown."
        ]);

        $postData = json_encode([
            "model" => "gpt-4o-mini",
            "messages" => $messages,
            "temperature" => 1
        ]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);
        $response = json_decode($response, true);
        $latest_reply = $response['choices'][0]['message']['content'];

        for ($i = 10; $i >= 2; $i--) {
            $_POST["reply_$i"] = $_POST["reply_" . ($i - 1)] ?? '';
            $_POST["message_$i"] = $_POST["message_" . ($i - 1)] ?? '';
        }
        $_POST['reply_1'] = $latest_reply;
    } else {
        $_POST['reply_1'] = "Welcome! I'm LawGPT, here to assist with your understanding of Philippine law from 1989 to June 2024. Feel free to ask your questions. Remember, 'TOPBAR ASSIST PH' is a valuable resource for bar exam preparation!";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbox</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <style>
        .chat-bubble { max-width: 75%; margin: 5px 0; padding: 10px; border-radius: 15px; }
        .chat-bubble-receive { background-color: #f1f1f1; margin-right: auto; }
        .chat-bubble-send { background-color: #007bff; color: white; margin-left: auto; }
        .chat-container { height: 236px; overflow-y: auto; }
    </style>
</head>

<body>

<div class="container mt-3">
    <div class="chat-container border p-3 mb-2">
        <?php for ($i = 10; $i >= 1; $i--): 
            $replyVar = $_POST["reply_$i"] ?? null;
            $messageVar = $_POST["message_$i"] ?? null;
            if (!empty($replyVar)): ?>
            <div class="d-flex">
                <div class="chat-bubble chat-bubble-receive"><?= htmlspecialchars($replyVar); ?></div>
            </div>
            <?php if (!empty($messageVar) && $i !== 1): ?>
            <div class="d-flex justify-content-end">
                <div class="chat-bubble chat-bubble-send"><?= htmlspecialchars($messageVar); ?></div>
            </div>
            <?php endif; ?>
        <?php endif; endfor; ?>
    </div>

    <!-- Chat Input -->
    <form method="post" action="">
        <?php for ($i = 10; $i >= 1; $i--): ?>
            <input type="hidden" name="reply_<?= $i ?>" value="<?= htmlspecialchars($_POST["reply_$i"] ?? ''); ?>">
            <input type="hidden" name="message_<?= $i ?>" value="<?= htmlspecialchars($_POST["message_$i"] ?? ''); ?>">
        <?php endfor; ?>
        <div class="input-group">
            <input type="text" name="message_1" class="form-control" placeholder="Type a message">
            <button class="btn btn-primary" type="submit">Send</button>
        </div>
    </form>
</div>

<script>
    document.querySelector('form').addEventListener('submit', function (event) {
        event.preventDefault();
        const sendButton = event.target.querySelector('button[type="submit"]');
        sendButton.disabled = true;
        const inputField = event.target.querySelector('input[type="text"]');
        const message = inputField.value.trim();
        if (message) {
            this.submit();
        } else {
            sendButton.disabled = false;
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const chatContainer = document.querySelector('.chat-container');
        chatContainer.scrollTop = chatContainer.scrollHeight;
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>