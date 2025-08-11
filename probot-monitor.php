<?php
/**
 * ProBot Payment Monitor
 * Monitors Discord channel for ProBot credit transfers and updates user wallets
 */

require_once 'database.php';
require_once 'config.php';

class ProbotMonitor {
    private $db;
    private $config;
    private $botToken;
    private $channelId;
    
    public function __construct() {
        $this->db = new Database();
        $this->config = require 'config.php';
        $this->botToken = $this->config['discord']['bot_token'];
        $this->channelId = '1319029928825589780'; // ProBot credit channel
    }
    
    /**
     * Check for new ProBot credit messages
     */
    public function checkForPayments() {
        $messages = $this->getChannelMessages();
        
        if (!$messages) {
            return false;
        }
        
        foreach ($messages as $message) {
            $this->processMessage($message);
        }
        
        return true;
    }
    
    /**
     * Get recent messages from the ProBot channel
     */
    private function getChannelMessages($limit = 10) {
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
            error_log("Failed to fetch messages: HTTP {$httpCode}");
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
            return false;
        }
        
        // Check if message contains credit transfer
        $content = $message['content'] ?? '';
        $embeds = $message['embeds'] ?? [];
        
        // Parse ProBot credit transfer message
        $transferData = $this->parseTransferMessage($content, $embeds);
        
        if ($transferData) {
            $this->processTransfer($transferData, $message);
        }
    }
    
    /**
     * Parse ProBot transfer message to extract transfer details
     */
    private function parseTransferMessage($content, $embeds) {
        // ProBot transfer patterns
        $patterns = [
            // Pattern for credit transfer: "✅ Successfully transferred 100 credits to @user"
            '/✅.*transferred\s+(\d+)\s+credits?\s+to\s+<@(\d+)>/i',
            // Pattern for embed transfers
            '/transferred\s+(\d+)\s+credits?\s+to/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return [
                    'amount' => (int)$matches[1],
                    'recipient_id' => $matches[2] ?? null
                ];
            }
        }
        
        // Check embeds for transfer information
        foreach ($embeds as $embed) {
            $description = $embed['description'] ?? '';
            $title = $embed['title'] ?? '';
            
            // Check embed description
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $description, $matches)) {
                    return [
                        'amount' => (int)$matches[1],
                        'recipient_id' => $matches[2] ?? null
                    ];
                }
            }
        }
        
        return false;
    }
    
    /**
     * Process a valid credit transfer
     */
    private function processTransfer($transferData, $message) {
        $amount = $transferData['amount'];
        $recipientId = $transferData['recipient_id'];
        $messageId = $message['id'];
        
        // Check if this transfer was already processed
        if ($this->isTransferProcessed($messageId)) {
            return false;
        }
        
        // Get user by Discord ID
        $user = $this->db->getUserByDiscordId($recipientId);
        
        if (!$user) {
            error_log("User not found for Discord ID: {$recipientId}");
            return false;
        }
        
        try {
            // Add credits to user account
            $this->db->addCredits(
                $recipientId,
                $amount,
                "ProBot credit transfer - {$amount} credits",
                $messageId
            );
            
            // Mark transfer as processed
            $this->markTransferProcessed($messageId, $recipientId, $amount);
            
            error_log("Successfully added {$amount} credits to user {$recipientId}");
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to process transfer: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a transfer was already processed
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
     * Mark a transfer as processed
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
    
    /**
     * Run the monitor (can be called via cron or webhook)
     */
    public function run() {
        try {
            $this->checkForPayments();
            echo json_encode(['status' => 'success', 'message' => 'Payment monitoring completed']);
        } catch (Exception $e) {
            error_log("ProBot monitor error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}

// If called directly, run the monitor
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $monitor = new ProbotMonitor();
    $monitor->run();
}
?>
