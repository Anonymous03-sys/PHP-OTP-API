<?php
header('Content-Type: application/json');

// Check if the SendGrid key is available
$key = getenv('SENDGRID_API_KEY');

if ($key) {
	echo json_encode([
		'success' => true,
		'message' => 'SendGrid API key is set on the server.',
		'partial_key' => substr($key, 0, 4) . '****' // Never expose the full key
	]);
} else {
	echo json_encode([
		'success' => false,
		'message' => 'SENDGRID_API_KEY is NOT set on the server.'
	]);
}
