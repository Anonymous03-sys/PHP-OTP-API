<?php
// 🔹 Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 🔹 Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json');
error_reporting(0); // hide PHP warnings/notices

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

try {
    $email = $_GET['email'] ?? '';
    $school_id = $_GET['school_id'] ?? '';

    if (!$email || !$school_id) {
        echo json_encode(['success' => false, 'message' => 'Email or school_id missing']);
        exit;
    }

    // Generate 6-digit OTP
    $otp = rand(100000, 999999);

    // Save OTP with expiry 5 mins
    $storageFile = 'otp_storage.json';
    $storage = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) : [];
    $storage[$email] = ['otp' => $otp, 'expiry' => time() + 300];
    file_put_contents($storageFile, json_encode($storage));

    // Send OTP email
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'codetology.adm1n@gmail.com';
    $mail->Password = 'ttqm bxgs xfxz dgzp'; // app password
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    $mail->setFrom('codetology.adm1n@gmail.com', 'Your App');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Your OTP Code';
    $mail->Body = "Your OTP is: <b>$otp</b>";
    $mail->send();

    echo json_encode(['success' => true, 'message' => 'OTP sent']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (\Throwable $t) {
    echo json_encode(['success' => false, 'message' => 'Unexpected error']);
}


// header("Access-Control-Allow-Origin: *");
// header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// header("Access-Control-Allow-Headers: Content-Type");

// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
//     exit(0);
// }


// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;

// require 'vendor/autoload.php';

// header('Content-Type: application/json');

// try {
//     // your OTP logic here
//     echo json_encode(['success'=>true,'message'=>'OTP sent']);
// } catch(Exception $e) {
//     echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
// }

// $storageFile = 'otp_storage.json';
// $storage = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) : [];

// $email = $_GET['email'] ?? '';
// $school_id = $_GET['school_id'] ?? '';

// if (!$email || !$school_id) {
//     echo json_encode(['success' => false, 'message' => 'Email or school_id missing']);
//     exit;
// }

// // Generate 6-digit OTP
// $otp = rand(100000, 999999);

// // Save OTP with expiry 5 mins
// $storage[$email] = ['otp' => $otp, 'expiry' => time() + 300];
// file_put_contents($storageFile, json_encode($storage));

// $mail = new PHPMailer(true);

// try {
//     //Server settings
//     $mail->isSMTP();
//     $mail->Host = 'smtp.gmail.com';
//     $mail->SMTPAuth = true;
//     $mail->Username = 'codetology.adm1n@gmail.com'; // your Gmail
//     $mail->Password = 'ttqm bxgs xfxz dgzp';     // App Password
//     $mail->SMTPSecure = 'tls';
//     $mail->Port = 587;

//     //Recipients
//     $mail->setFrom('codetology.adm1n@gmail.com', 'Your App');
//     $mail->addAddress($email);

//     //Content
//     $mail->isHTML(true);
//     $mail->Subject = 'Your OTP Code';
//     $mail->Body    = "Your OTP is: <b>$otp</b>";

//     $mail->send();
//     echo json_encode(['success' => true, 'message' => 'OTP sent']);
// } catch (Exception $e) {
//     echo json_encode(['success' => false, 'message' => $mail->ErrorInfo]);
// }
