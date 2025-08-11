<?php
session_start();

// Load configuration
try {
    $config = require_once 'config.php';
} catch (Exception $e) {
    die('Configuration file not found. Please check your environment variables.');
}

// Check if we have an authorization code
if (!isset($_GET['code'])) {
    header('Location: index.php?error=no_code');
    exit;
}

$code = $_GET['code'];

// Exchange code for access token
$token_url = 'https://discord.com/api/oauth2/token';
$token_data = [
    'client_id' => $config['DISCORD_CLIENT_ID'],
    'client_secret' => $config['DISCORD_CLIENT_SECRET'],
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $config['REDIRECT_URI']
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$token_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    header('Location: index.php?error=token_exchange_failed');
    exit;
}

$token_data = json_decode($token_response, true);

if (!isset($token_data['access_token'])) {
    header('Location: index.php?error=no_access_token');
    exit;
}

$access_token = $token_data['access_token'];

// Get user information
$user_url = 'https://discord.com/api/users/@me';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $user_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$user_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    header('Location: index.php?error=user_fetch_failed');
    exit;
}

$user_data = json_decode($user_response, true);

// Store user data in session
$_SESSION['discord_user'] = [
    'id' => $user_data['id'],
    'username' => $user_data['username'],
    'discriminator' => $user_data['discriminator'],
    'email' => $user_data['email'] ?? '',
    'avatar' => $user_data['avatar'],
    'avatar_url' => $user_data['avatar'] 
        ? "https://cdn.discordapp.com/avatars/{$user_data['id']}/{$user_data['avatar']}.png"
        : "https://cdn.discordapp.com/embed/avatars/" . ($user_data['discriminator'] % 5) . ".png",
    'access_token' => $access_token
];

// Redirect to main page
header('Location: index.php?login=success');
exit;
?>
