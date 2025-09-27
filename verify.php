<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

header('Content-Type: application/json');

$storageFile = sys_get_temp_dir() . '/otp_storage.json';
$storage = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) : [];

$email = $_GET['email'] ?? '';
$enteredOtp = $_GET['otp'] ?? '';

if (!$email || !$enteredOtp) {
	echo json_encode(['success' => false, 'message' => 'Email or OTP missing']);
	exit;
}

if (!isset($storage[$email])) {
	echo json_encode(['success' => false, 'message' => 'No OTP found']);
	exit;
}

$otpData = $storage[$email];

if ($otpData['expiry'] < time()) {
	unset($storage[$email]);
	file_put_contents($storageFile, json_encode($storage));
	echo json_encode(['success' => false, 'message' => 'OTP expired']);
	exit;
}

if ($otpData['otp'] == $enteredOtp) {
	unset($storage[$email]);
	file_put_contents($storageFile, json_encode($storage));
	echo json_encode(['success' => true, 'message' => 'OTP verified']);
} else {
	echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
}
