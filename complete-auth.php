<?php
session_start();

// Load configuration
try {
    $config = require_once 'config.php';
    require_once 'database.php';
} catch (Exception $e) {
    die('Configuration error: ' . $e->getMessage());
}

// Check if we have an authorization code from Discord
$auth_code = $_GET['code'] ?? null;

if (!$auth_code) {
    header('Location: index.php?error=no_auth_code');
    exit;
}

// Discord OAuth configuration
$client_id = $config['DISCORD_CLIENT_ID'];
$client_secret = $config['DISCORD_CLIENT_SECRET'];
$redirect_uri = $config['REDIRECT_URI'];

// Exchange authorization code for access token
$token_url = 'https://discord.com/api/oauth2/token';
$token_data = [
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'grant_type' => 'authorization_code',
    'code' => $auth_code,
    'redirect_uri' => $redirect_uri
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

$token_response = curl_exec($ch);
$token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($token_http_code !== 200) {
    header('Location: index.php?error=token_exchange_failed');
    exit;
}

$token_data = json_decode($token_response, true);

if (!isset($token_data['access_token'])) {
    header('Location: index.php?error=no_access_token');
    exit;
}

// Get user information from Discord
$user_url = 'https://discord.com/api/users/@me';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $user_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token_data['access_token']
]);

$user_response = curl_exec($ch);
$user_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($user_http_code !== 200) {
    header('Location: index.php?error=user_fetch_failed');
    exit;
}

$user_data = json_decode($user_response, true);

// Create avatar URL
$avatar_url = $user_data['avatar'] 
    ? "https://cdn.discordapp.com/avatars/{$user_data['id']}/{$user_data['avatar']}.png"
    : "https://cdn.discordapp.com/embed/avatars/" . (intval($user_data['discriminator']) % 5) . ".png";

// Store user data in session
$_SESSION['discord_user'] = [
    'id' => $user_data['id'],
    'username' => $user_data['username'],
    'discriminator' => $user_data['discriminator'],
    'avatar' => $user_data['avatar'],
    'avatar_url' => $avatar_url,
    'email' => $user_data['email'] ?? null,
    'access_token' => $token_data['access_token']
];

// Create or update user in database
try {
    $db = new Database();
    $db->createOrUpdateUser($user_data);
} catch (Exception $e) {
    error_log('Database error: ' . $e->getMessage());
    // Continue anyway, session is still valid
}

// Redirect to main page
header('Location: index.php?login=success');
exit;
?>
