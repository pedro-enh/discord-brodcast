<?php
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate required fields
if (!$data || !isset($data['id']) || !isset($data['username'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Store user data in session
$_SESSION['discord_user'] = [
    'id' => $data['id'],
    'username' => $data['username'],
    'discriminator' => $data['discriminator'] ?? '0000',
    'avatar' => $data['avatar'] ?? null,
    'avatar_url' => $data['avatar_url']
];

$_SESSION['access_token'] = $data['access_token'] ?? null;

// Return success
echo json_encode(['success' => true]);
?>
