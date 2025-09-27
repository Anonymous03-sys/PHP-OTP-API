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
    $emailContent->setFrom("codetology.adm1n@gmail.com", "Codetology-Admin");
    $emailContent->setSubject("Your One-Time Password (OTP) Code");
    $emailContent->addTo($email);
    $emailContent->addContent(
        " text/html",
        "
    <div style='font-family: Arial, sans-serif; color: #333;'>
        <h2 style='color: #4CAF50;'>Codetology OTP Verification</h2>
        <p>Hello,</p>
        <p>We received a request to log in to your Codetology account associated with this email address.</p>
        <p>Your One-Time Password (OTP) is:</p>
        <h1 style='background: #f4f4f4; padding: 10px; display: inline-block; letter-spacing: 3px;'>$otp</h1>
        <p>This OTP is valid for <strong>5 minutes</strong>. Please do not share it with anyone.</p>
        <p>If you did not request this code, please ignore this email.</p>
        <hr>
        <p style='font-size: 12px; color: #999;'>
            Codetology Inc.<br>
            San Isidro, Philippines<br>
            This is an automated message. Please do not reply.
        </p>
    </div>
    "
    );

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
