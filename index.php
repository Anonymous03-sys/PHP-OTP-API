<?php
// 🔹 Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
header('Content-Type: application/json');

require __DIR__ . '/vendor/autoload.php'; // Composer autoload

$email = $_GET['email'] ?? '';
$school_id = $_GET['school_id'] ?? '';

if (!$email || !$school_id) {
    echo json_encode(['success' => false, 'message' => 'Email or school_id missing']);
    exit;
}

// Generate 6-digit OTP
$otp = rand(100000, 999999);

// Save OTP with expiry 5 mins
$storageFile = sys_get_temp_dir() . '/otp_storage.json';
$storage = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) : [];
$storage[$email] = ['otp' => $otp, 'expiry' => time() + 300];
file_put_contents($storageFile, json_encode($storage));

try {
    $sendgrid = new \SendGrid(getenv('SENDGRID_API_KEY')); // Replace with your API Key

    $emailContent = new \SendGrid\Mail\Mail();
    $emailContent->setFrom("codetology.adm1n@gmail.com", "Codetology");
    $emailContent->setSubject("Your OTP Code");
    $emailContent->addTo($email);
    $emailContent->addContent("text/html", "Your OTP is: <b>$otp</b>");

    $response = $sendgrid->send($emailContent);

    if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
        echo json_encode(['success' => true, 'message' => 'OTP sent']);
    } else {
        $message = $response->body() ?: 'Failed to send OTP';
        echo json_encode(['success' => false, 'message' => $message]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
