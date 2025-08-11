<?php
// Load configuration
try {
    $config = require_once 'config.php';
} catch (Exception $e) {
    die('Configuration file not found. Please check your environment variables.');
}

// Discord OAuth URL
$client_id = $config['DISCORD_CLIENT_ID'];
$redirect_uri = urlencode($config['REDIRECT_URI']);
$scope = urlencode('identify email guilds');

$discord_oauth_url = "https://discord.com/api/oauth2/authorize?client_id={$client_id}&redirect_uri={$redirect_uri}&response_type=code&scope={$scope}";

// Redirect to Discord OAuth
header("Location: {$discord_oauth_url}");
exit;
?>
