<?php
// Discord Broadcaster Pro Configuration
// This file loads configuration from environment variables (.env file)

// Load environment variables
require_once 'env-loader.php';

return [
    // Discord OAuth Configuration
    // These values are loaded from .env file or Railway environment variables
    'DISCORD_CLIENT_ID' => env('DISCORD_CLIENT_ID', ''),
    'DISCORD_CLIENT_SECRET' => env('DISCORD_CLIENT_SECRET', ''),
    
    // Your website URL
    // This value is loaded from .env file or Railway environment variables
    'REDIRECT_URI' => env('REDIRECT_URI', 'https://yourdomain.com/auth.php'),
    
    // Bot Token (required for broadcasting functionality)
    // This value is loaded from .env file or Railway environment variables
    'BOT_TOKEN' => env('BOT_TOKEN', ''),
    
    // Optional configuration
    'APP_ENV' => env('APP_ENV', 'production'),
    'DEBUG' => env('DEBUG', false)
];
?>
