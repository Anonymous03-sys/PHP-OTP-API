<?php
session_start();
header('Content-Type: application/json');

$email = $_GET['email'] ?? '';
$enteredOtp = $_GET['otp'] ?? '';

if (!$email || !$enteredOtp) {
	echo json_encode(['success' => false, 'message' => 'Email and OTP required']);
	exit;
}

$otpData = $_SESSION['otp_data'][$email] ?? null;
if (!$otpData) {
	echo json_encode(['success' => false, 'message' => 'No OTP found for this email']);
	exit;
}

// Check expiry
if (time() > $otpData['expiry']) {
	unset($_SESSION['otp_data'][$email]);
	echo json_encode(['success' => false, 'message' => 'OTP expired']);
	exit;
}

// Check OTP
if ($enteredOtp == $otpData['otp']) {
	unset($_SESSION['otp_data'][$email]);
	echo json_encode(['success' => true, 'message' => 'OTP verified']);
} else {
	echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
}
