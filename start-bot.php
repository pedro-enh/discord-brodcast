<?php
/**
 * Bot Starter Script for Railway
 * Keeps the Discord bot running continuously
 */

// Set time limit to unlimited
set_time_limit(0);

// Enable output buffering for real-time logs
ob_implicit_flush(true);
ob_end_flush();

echo "🚀 Starting Discord Bot Service...\n";

// Include the bot
require_once 'discord-bot.php';

try {
    $bot = new DiscordBot();
    echo "✅ Bot initialized successfully\n";
    
    // Start the bot
    $bot->start();
    
} catch (Exception $e) {
    echo "❌ Bot failed to start: " . $e->getMessage() . "\n";
    
    // Wait and retry
    echo "🔄 Retrying in 30 seconds...\n";
    sleep(30);
    
    // Restart the script
    exec("php " . __FILE__);
}
?>
