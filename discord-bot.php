<?php
/**
 * Discord Bot for Payment Monitoring and Confirmation
 * Monitors ProBot transfers and sends confirmation messages
 */

require_once 'database.php';
require_once 'config.php';

class DiscordBot {
    private $botToken;
    private $channelId;
    private $db;
    private $config;
    private $lastMessageId = null;
    
    public function __construct() {
        $this->config = require 'config.php';
        $this->botToken = $this->config['BOT_TOKEN'];
        $this->channelId = '1319029928825589780'; // ProBot credit channel
        $this->db = new Database();
        
        if (empty($this->botToken)) {
            throw new Exception('Bot token is required');
        }
    }
    
    /**
     * Start the bot monitoring loop
     */
    public function start() {
        echo "🤖 Discord Bot started monitoring channel: {$this->channelId}\n";
        
        while (true) {
            try {
                $this->checkForNewMessages();
                sleep(5); // Check every 5 seconds
            } catch (Exception $e) {
                echo "❌ Error: " . $e->getMessage() . "\n";
                sleep(10); // Wait longer on error
            }
        }
    }
    
    /**
     * Check for new messages in the monitored channel
     */
    private function checkForNewMessages() {
        $messages = $this->getChannelMessages(5);
        
        if (!$messages) {
            return;
        }
        
        foreach ($messages as $message) {
            // Skip if we've already processed this message
            if ($this->lastMessageId && $message['id'] <= $this->lastMessageId) {
                continue;
            }
            
            $this->processMessage($message);
            $this->lastMessageId = $message['id'];
        }
    }
    
    /**
     * Get recent messages from the channel
     */
    private function getChannelMessages($limit = 5) {
        $url = "https://discord.com/api/v10/channels/{$this->channelId}/messages?limit={$limit}";
        
        $headers = [
            'Authorization: Bot ' . $this->botToken,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo "⚠️ Failed to fetch messages: HTTP {$httpCode}\n";
            return false;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Process a message to check if it's a ProBot credit transfer
     */
    private function processMessage($message) {
        // Check if message is from ProBot
        if (!isset($message['author']['id']) || $message['author']['id'] !== '282859044593598464') {
            return;
        }
        
        echo "📨 Processing ProBot message: " . substr($message['content'], 0, 100) . "...\n";
        
        // Parse transfer data
        $transferData = $this->parseTransferMessage($message);
        
        if ($transferData) {
            echo "💰 Found transfer: {$transferData['amount']} credits to {$transferData['recipient_id']}\n";
            $this->processTransfer($transferData, $message);
        }
    }
    
    /**
     * Parse ProBot transfer message
     */
    private function parseTransferMessage($message) {
        $content = $message['content'] ?? '';
        $embeds = $message['embeds'] ?? [];
        
        // ProBot transfer patterns
        $patterns = [
            '/✅.*transferred\s+(\d+)\s+credits?\s+to\s+<@(\d+)>/i',
            '/successfully\s+transferred\s+(\d+)\s+credits?\s+to\s+<@(\d+)>/i',
            '/تم\s+تحويل\s+(\d+)\s+.*إلى\s+<@(\d+)>/i'
        ];
        
        // Check message content
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return [
                    'amount' => (int)$matches[1],
                    'recipient_id' => $matches[2],
                    'sender_id' => $this->extractSenderId($content, $message)
                ];
            }
        }
        
        // Check embeds
        foreach ($embeds as $embed) {
            $description = $embed['description'] ?? '';
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $description, $matches)) {
                    return [
                        'amount' => (int)$matches[1],
                        'recipient_id' => $matches[2],
                        'sender_id' => $this->extractSenderId($description, $message)
                    ];
                }
            }
        }
        
        return false;
    }
    
    /**
     * Extract sender ID from message
     */
    private function extractSenderId($content, $message) {
        // Try to find sender mention in the message
        if (preg_match('/<@(\d+)>.*transferred|من\s+<@(\d+)>/', $content, $matches)) {
            return $matches[1] ?? $matches[2];
        }
        
        // If not found, try to get from message context or embeds
        return null;
    }
    
    /**
     * Process a valid credit transfer
     */
    private function processTransfer($transferData, $message) {
        $amount = $transferData['amount'];
        $recipientId = $transferData['recipient_id'];
        $senderId = $transferData['sender_id'];
        $messageId = $message['id'];
        
        // Check if this transfer was already processed
        if ($this->isTransferProcessed($messageId)) {
            echo "⚠️ Transfer already processed: {$messageId}\n";
            return;
        }
        
        // Check if recipient is our bot/service
        $ourBotId = '675332512414695441'; // Your service recipient ID
        if ($recipientId !== $ourBotId) {
            echo "ℹ️ Transfer not for our service: recipient {$recipientId}\n";
            return;
        }
        
        // Get user by Discord ID
        $user = $this->db->getUserByDiscordId($senderId);
        
        if (!$user) {
            echo "⚠️ User not found for Discord ID: {$senderId}\n";
            // Send message to user to register first
            $this->sendRegistrationMessage($senderId);
            return;
        }
        
        try {
            // Calculate credits to add (5000 ProBot credits = 10 broadcast messages)
            $broadcastCredits = floor($amount / 500); // 500 ProBot credits = 1 broadcast message
            
            // Add credits to user account
            $this->db->addCredits(
                $senderId,
                $broadcastCredits,
                "ProBot credit transfer - {$amount} ProBot credits",
                $messageId
            );
            
            // Mark transfer as processed
            $this->markTransferProcessed($messageId, $senderId, $amount);
            
            // Send confirmation message to user
            $this->sendConfirmationMessage($senderId, $amount, $broadcastCredits);
            
            echo "✅ Successfully processed transfer: {$amount} ProBot credits → {$broadcastCredits} broadcast messages for user {$senderId}\n";
            
        } catch (Exception $e) {
            echo "❌ Failed to process transfer: " . $e->getMessage() . "\n";
            $this->sendErrorMessage($senderId, $e->getMessage());
        }
    }
    
    /**
     * Send confirmation message to user
     */
    private function sendConfirmationMessage($userId, $probotCredits, $broadcastCredits) {
        $message = [
            'content' => "✅ **Payment Successful!**\n\n" .
                        "💰 **Received:** {$probotCredits} ProBot Credits\n" .
                        "📨 **Added:** {$broadcastCredits} Broadcast Messages\n\n" .
                        "🎉 Your credits have been added to your wallet!\n" .
                        "🌐 Visit: https://discord-brodcast.up.railway.app/wallet.php"
        ];
        
        $this->sendDirectMessage($userId, $message);
    }
    
    /**
     * Send registration message to user
     */
    private function sendRegistrationMessage($userId) {
        $message = [
            'content' => "⚠️ **Registration Required**\n\n" .
                        "To receive credits, please register first:\n" .
                        "🌐 https://discord-brodcast.up.railway.app/login.php\n\n" .
                        "After registration, your payment will be processed automatically!"
        ];
        
        $this->sendDirectMessage($userId, $message);
    }
    
    /**
     * Send error message to user
     */
    private function sendErrorMessage($userId, $error) {
        $message = [
            'content' => "❌ **Payment Processing Error**\n\n" .
                        "There was an issue processing your payment:\n" .
                        "`{$error}`\n\n" .
                        "Please contact support or try again."
        ];
        
        $this->sendDirectMessage($userId, $message);
    }
    
    /**
     * Send direct message to user
     */
    private function sendDirectMessage($userId, $message) {
        // First, create a DM channel
        $dmChannel = $this->createDMChannel($userId);
        
        if (!$dmChannel) {
            echo "❌ Failed to create DM channel for user {$userId}\n";
            return false;
        }
        
        // Send message to DM channel
        $url = "https://discord.com/api/v10/channels/{$dmChannel['id']}/messages";
        
        $headers = [
            'Authorization: Bot ' . $this->botToken,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            echo "📤 Sent confirmation message to user {$userId}\n";
            return true;
        } else {
            echo "❌ Failed to send message to user {$userId}: HTTP {$httpCode}\n";
            return false;
        }
    }
    
    /**
     * Create DM channel with user
     */
    private function createDMChannel($userId) {
        $url = "https://discord.com/api/v10/users/@me/channels";
        
        $data = [
            'recipient_id' => $userId
        ];
        
        $headers = [
            'Authorization: Bot ' . $this->botToken,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        return false;
    }
    
    /**
     * Check if transfer was already processed
     */
    private function isTransferProcessed($messageId) {
        $stmt = $this->db->getPdo()->prepare("
            SELECT id FROM transactions 
            WHERE probot_transaction_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$messageId]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Mark transfer as processed
     */
    private function markTransferProcessed($messageId, $discordId, $amount) {
        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO processed_transfers (message_id, discord_id, amount, processed_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        // Create table if it doesn't exist
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS processed_transfers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id TEXT UNIQUE NOT NULL,
                discord_id TEXT NOT NULL,
                amount INTEGER NOT NULL,
                processed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $stmt->execute([$messageId, $discordId, $amount]);
    }
}

// Run the bot if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $bot = new DiscordBot();
        $bot->start();
    } catch (Exception $e) {
        echo "❌ Bot startup failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
